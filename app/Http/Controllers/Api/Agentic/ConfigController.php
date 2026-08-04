<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Api\Agentic\Concerns\ValidatesAgentSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant setup for an agent.
 *
 * Separate from the agent row on purpose. A platform agent is shared by every
 * organisation, but each one connects it to their own Google Sheet, their own
 * API key, their own workspace. Storing that on the agent would mean cloning
 * the whole catalogue entry just to hold one credential.
 *
 *   GET  /agentic/agents/{id}/config   the schema, saved answers, which
 *                                      secrets are set (never their values)
 *   PUT  /agentic/agents/{id}/config   save answers; secrets are encrypted
 *   DELETE /agentic/agents/{id}/config disconnect
 *
 * Secret fields live in their own encrypted column so the read path cannot
 * return one by accident - it simply never reads that column.
 */
class ConfigController extends Controller
{
    use ResolvesAgenticContext;
    use ValidatesAgentSchema;

    private const AGENTS = 'agentic_agents';
    private const CONFIGS = 'agentic_agent_configs';

    /** Platform agents are visible to every tenant; tenant agents only to their owner. */
    private function findAgent(int $sid, int $id)
    {
        return DB::table(self::AGENTS)
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->where(function ($query) use ($sid) {
                $query->where('sub_institute_id', $sid)->orWhereNull('sub_institute_id');
            })
            ->first();
    }

    private function configRow(int $sid, int $agentId)
    {
        return DB::table(self::CONFIGS)
            ->where('sub_institute_id', $sid)
            ->where('agent_id', $agentId)
            ->first();
    }

    public function show(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $agent = $this->findAgent($sid, (int) $id);

        if (!$agent) {
            return $this->fail('Agent not found', 404);
        }

        $schema = $this->decodeSchema($agent->config_schema);
        $row = $this->configRow($sid, (int) $id);

        $values = [];
        if ($row && $row->values) {
            $decoded = json_decode($row->values, true);
            $values = is_array($decoded) ? $decoded : [];
        }

        return $this->ok('Agent configuration', [
            'agent_id'    => (int) $id,
            'agent_name'  => $agent->name,
            'schema'      => $schema,
            // Non-secret answers only. Secrets are reported as set / not set.
            'values'      => $values,
            'secrets_set' => $this->secretsSet($row, $schema),
            'configured'  => $this->isConfigured($schema, $row),
            'updated_at'  => $row->updated_at ?? null,
        ]);
    }

    public function update(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $agent = $this->findAgent($sid, (int) $id);

        if (!$agent) {
            return $this->fail('Agent not found', 404);
        }

        $schema = $this->decodeSchema($agent->config_schema);

        if ($schema === []) {
            return $this->fail('This agent has nothing to configure.', 422);
        }

        $existing = $this->configRow($sid, (int) $id);
        $storedValues = [];
        $storedSecrets = $this->decryptSecrets($existing);

        if ($existing && $existing->values) {
            $decoded = json_decode($existing->values, true);
            $storedValues = is_array($decoded) ? $decoded : [];
        }

        // Values may arrive as a nested `values` object or as flat fields, so
        // that both a JSON body and a multipart upload work the same way.
        $submitted = $request->input('values');
        if (!is_array($submitted)) {
            $submitted = $request->all();
        }

        [$values, $secrets, $errors] = $this->split($schema, $submitted, $request, $storedValues, $storedSecrets);

        if ($errors !== []) {
            return response()->json([
                'status'  => 0,
                'message' => $this->summariseErrors($errors),
                'errors'  => $errors,
            ], 422);
        }

        $payload = [
            'values'        => json_encode($values),
            'secrets'       => $secrets === [] ? null : Crypt::encryptString(json_encode($secrets)),
            'configured_by' => $context['user_id'],
            'updated_at'    => now(),
        ];

        if ($existing) {
            DB::table(self::CONFIGS)->where('id', $existing->id)->update($payload);
        } else {
            DB::table(self::CONFIGS)->insert($payload + [
                'sub_institute_id' => $sid,
                'agent_id'         => (int) $id,
                'created_at'       => now(),
            ]);
        }

        $row = $this->configRow($sid, (int) $id);

        return $this->ok('Configuration saved', [
            'agent_id'    => (int) $id,
            'values'      => $values,
            'secrets_set' => $this->secretsSet($row, $schema),
            'configured'  => $this->isConfigured($schema, $row),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        if (!$this->findAgent($sid, (int) $id)) {
            return $this->fail('Agent not found', 404);
        }

        DB::table(self::CONFIGS)
            ->where('sub_institute_id', $sid)
            ->where('agent_id', (int) $id)
            ->delete();

        return $this->ok('Configuration removed', ['agent_id' => (int) $id]);
    }

    /**
     * Sort submitted answers into plain values and secrets.
     *
     * A blank secret means "leave the stored one alone" - otherwise saving an
     * unrelated field would wipe a credential the user cannot retype because
     * it was never shown back to them.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,string>}
     */
    private function split(array $schema, array $submitted, Request $request, array $storedValues, array $storedSecrets): array
    {
        $secrets = $storedSecrets;
        $plain = [];
        $errors = [];

        // Secret and file fields are handled here; the rest go through the
        // shared validator below.
        $scalarSchema = [];

        foreach ($schema as $field) {
            $name = $field['name'];

            if ($field['type'] === 'file') {
                $uploaded = $request->file($name);

                if ($uploaded) {
                    $contents = @file_get_contents($uploaded->getRealPath());

                    if ($contents === false) {
                        $errors[$name] = $field['label'] . ' could not be read.';
                        continue;
                    }

                    if ($field['secret']) {
                        $secrets[$name] = $contents;
                        $plain[$name . '_filename'] = $uploaded->getClientOriginalName();
                    } else {
                        $plain[$name] = $contents;
                    }

                    continue;
                }

                // Nothing uploaded: keep what is stored, or complain if this is
                // the first time and the field is required.
                $alreadyHave = $field['secret']
                    ? array_key_exists($name, $storedSecrets)
                    : array_key_exists($name, $storedValues);

                if (!$alreadyHave && $field['required']) {
                    $errors[$name] = $field['label'] . ' is required.';
                } elseif (!$field['secret'] && $alreadyHave) {
                    $plain[$name] = $storedValues[$name];
                } elseif ($field['secret'] && $alreadyHave) {
                    $plain[$name . '_filename'] = $storedValues[$name . '_filename'] ?? null;
                }

                continue;
            }

            if ($field['secret']) {
                $value = $submitted[$name] ?? null;

                if ($value === null || trim((string) $value) === '') {
                    if (!array_key_exists($name, $storedSecrets) && $field['required']) {
                        $errors[$name] = $field['label'] . ' is required.';
                    }

                    continue;
                }

                $secrets[$name] = (string) $value;
                continue;
            }

            $scalarSchema[] = $field;
        }

        $result = $this->applySchema($scalarSchema, $submitted);

        return [
            array_merge($plain, $result['values']),
            $secrets,
            array_merge($errors, $result['errors']),
        ];
    }

    /** @return array<string, mixed> */
    private function decryptSecrets($row): array
    {
        if (!$row || !$row->secrets) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($row->secrets), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $exception) {
            // A rotated APP_KEY makes old ciphertext unreadable. Treat it as
            // "not configured" so the user can re-enter it, rather than 500.
            return [];
        }
    }

    /**
     * Names of the secret fields that hold a value - so the UI can show
     * "Key file set" without ever receiving the key.
     *
     * @return array<int, string>
     */
    private function secretsSet($row, array $schema): array
    {
        $secrets = $this->decryptSecrets($row);
        $names = [];

        foreach ($schema as $field) {
            if ($field['secret'] && array_key_exists($field['name'], $secrets)) {
                $names[] = $field['name'];
            }
        }

        return $names;
    }

    /** Configured means every required config field has an answer. */
    private function isConfigured(array $schema, $row): bool
    {
        if ($schema === []) {
            return true;
        }

        $values = [];
        if ($row && $row->values) {
            $decoded = json_decode($row->values, true);
            $values = is_array($decoded) ? $decoded : [];
        }

        $secrets = $this->decryptSecrets($row);

        foreach ($schema as $field) {
            if (!$field['required']) {
                continue;
            }

            $name = $field['name'];
            $have = $field['secret']
                ? array_key_exists($name, $secrets)
                : (array_key_exists($name, $values) && $values[$name] !== null && $values[$name] !== '');

            if (!$have) {
                return false;
            }
        }

        return true;
    }
}
