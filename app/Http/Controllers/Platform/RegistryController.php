<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The catalogue the Platform Services screens render and the writes validate against.
 *
 * One endpoint, so a screen physically cannot offer a setting the API would refuse:
 * both read `config/platform_services.php`, and neither has a second copy to drift from.
 *
 * `problems` rides along beside a working payload rather than being thrown at boot. An
 * inconsistent config file is a mistake worth shouting about, but throwing would take
 * down the console that would have told you which line was wrong.
 */
class RegistryController extends PlatformController
{
    public function __construct(private readonly PlatformRegistry $registry)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $this->scope($request);

            return $this->success('Platform registry.', $this->registry->payload() + [
                'problems' => $this->registry->problems(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
