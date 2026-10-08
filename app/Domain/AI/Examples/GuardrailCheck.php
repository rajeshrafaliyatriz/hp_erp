<?php

namespace App\Domain\AI\Examples;

use App\Services\Ai\AiRequestScope;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Throwable;

/**
 * Whether the signed-in user may perform a module's example write action, decided by the
 * rights the real route enforces.
 *
 * NOTHING HERE RESTATES A RULE
 *
 * The route is found in the live route table (method + uri from `G2gModuleExamples`) and
 * its own middleware list is read from it. Each middleware that is a RIGHT GATE
 * (`platformright`, `profile`, `subject`, `task.permission`, `menuright`, ...) is then run
 * through its own `handle()` with the caller's own token, exactly as the router would run it
 * for the real request, and the controller is never reached. So the answer cannot drift from
 * what the endpoint does: change the route's gate and this changes with it.
 *
 * Middleware that is not a right gate (sanitisers, throttles, the `api` group) is listed as
 * "not a gate" and not run. A gate this class does not know how to evaluate is reported as
 * not evaluated, never silently passed.
 */
final class GuardrailCheck
{
    /** Middleware aliases that decide who may do something. */
    private const GATES = ['platformright', 'anyaccess', 'profile', 'subject', 'task.permission', 'menuright', 'hrit.role', 'api.token', 'platform.owner'];

    /** What each gate means, for the person reading the result. */
    private const MEANING = [
        'platformright' => 'Your role must hold this screen right (tblgroupwise_rights_g2g).',
        'anyaccess' => 'Your role must be one of the listed profiles, or hold one of the listed screen rights.',
        'profile' => 'Your role must be one of the named profiles.',
        'subject' => 'Your role must be in this authority tier.',
        'task.permission' => 'Your role must hold this Task Management ability.',
        'menuright' => 'Your role must hold this menu right.',
        'hrit.role' => 'Your role must be one of the named HRIT roles.',
        'api.token' => 'You must present a valid token.',
        'platform.owner' => 'You must be a platform owner.',
    ];

    /**
     * The route's own gates, without running them. Used to describe the chain before a check.
     *
     * @param  array<string, mixed>  $action  `method` and `uri` from G2gModuleExamples
     * @return array{found:bool, route:string, gates:array<int, array<string,mixed>>, other:array<int,string>}
     */
    public function describe(array $action): array
    {
        $route = $this->find((string) $action['method'], (string) $action['uri']);
        $label = $action['method'] . ' /' . ltrim((string) $action['uri'], '/');

        if ($route === null) {
            return ['found' => false, 'route' => $label, 'gates' => [], 'other' => []];
        }

        $gates = [];
        $other = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name, $parameters] = $this->split($middleware);

            if (in_array($name, self::GATES, true)) {
                $gates[] = [
                    'gate' => $middleware,
                    'name' => $name,
                    'parameters' => $parameters,
                    'meaning' => self::MEANING[$name] ?? '',
                ];
            } else {
                $other[] = $middleware;
            }
        }

        return ['found' => true, 'route' => $label, 'gates' => $gates, 'other' => $other];
    }

    /**
     * Run the route's gates for the caller.
     *
     * @param  array<string, mixed>  $action
     * @return array{allowed:bool, route:string, found:bool, gates:array<int, array<string,mixed>>, other:array<int,string>}
     */
    public function evaluate(Request $current, AiRequestScope $scope, array $action): array
    {
        $described = $this->describe($action);

        if (! $described['found']) {
            // A route that cannot be found cannot be allowed; saying "allowed" would be a guess.
            return $described + ['allowed' => false];
        }

        $route = $this->find((string) $action['method'], (string) $action['uri']);
        $token = (string) $current->bearerToken();

        $results = [];
        $allowed = true;

        foreach ($described['gates'] as $gate) {
            $outcome = $this->run($route, $gate, $token);
            $results[] = $gate + $outcome;

            if ($outcome['passed'] === false) {
                $allowed = false;
            } elseif ($outcome['passed'] === null) {
                // Not evaluated is not passed.
                $allowed = false;
            }
        }

        return ['allowed' => $allowed] + array_merge($described, ['gates' => $results]);
    }

    /**
     * @param  array<string, mixed>  $gate
     * @return array{passed:bool|null, status:int|null, message:string}
     */
    private function run(Route $route, array $gate, string $token): array
    {
        try {
            $aliases = app('router')->getMiddleware();
            $class = $aliases[$gate['name']] ?? null;

            if ($class === null || ! class_exists($class)) {
                return ['passed' => null, 'status' => null, 'message' => 'This gate could not be loaded, so it was not evaluated.'];
            }

            $request = Request::create('/' . ltrim($route->uri(), '/'), $route->methods()[0]);
            $request->headers->set('Authorization', 'Bearer ' . $token);
            $request->headers->set('Accept', 'application/json');
            $request->setRouteResolver(fn () => $route);

            $marker = 'g2g-guardrail-check-passed';
            $response = app($class)->handle(
                $request,
                fn () => response()->json(['status' => $marker], 200),
                ...$gate['parameters']
            );

            $body = json_decode((string) $response->getContent(), true);

            if ($response->getStatusCode() === 200 && is_array($body) && ($body['status'] ?? null) === $marker) {
                return ['passed' => true, 'status' => 200, 'message' => 'Passed.'];
            }

            return [
                'passed' => false,
                'status' => $response->getStatusCode(),
                'message' => is_array($body) && isset($body['message']) ? (string) $body['message'] : 'Refused.',
            ];
        } catch (Throwable $e) {
            return ['passed' => null, 'status' => null, 'message' => 'This gate could not be evaluated: ' . class_basename($e) . '.'];
        }
    }

    private function find(string $method, string $uri): ?Route
    {
        $uri = ltrim($uri, '/');

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array(strtoupper($method), $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /** @return array{0:string, 1:array<int,string>} */
    private function split(string $middleware): array
    {
        $name = $middleware;
        $parameters = [];

        if (str_contains($middleware, ':')) {
            [$name, $raw] = explode(':', $middleware, 2);
            $parameters = $raw === '' ? [] : explode(',', $raw);
        }

        return [$name, $parameters];
    }
}
