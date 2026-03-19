<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\EventAnalytics;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Team;
use MariusCucuruz\DAMImporter\Models\User;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Contracts\Routing\ResponseFactory;
use MariusCucuruz\DAMImporter\Integrations\EventAnalytics\Models\EventAnalytic;

class EventAnalytics
{
    public function collectAnalytics(Request $request): Response|ResponseFactory
    {
        $data = json_decode($request->getContent(), true);
        $ip = $request->ip();
        $agent = $request->server('HTTP_USER_AGENT');
        $userId = data_get($data, 'events.0.user_id')
            ? User::find(data_get($data, 'events.0.user_id'))?->id
            : null;
        $teamId = data_get($data, 'events.0.team_id')
            ? Team::find(data_get($data, 'events.0.team_id'))?->id
            : null;

        foreach (data_get($data, 'events', []) as $event) {
            $urlPath = data_get($event, 'url_path');

            [$modelType, $modelId, $eventValue] = $this->resolveModelFromUrl($urlPath);

            EventAnalytic::query()->insertOrIgnore([
                'id'             => uuid(),
                'client_id'      => data_get($event, 'client_id'),
                'user_id'        => $userId,
                'team_id'        => $teamId,
                'session_id'     => data_get($event, 'session_id') ?: session()->getId(),
                'model_type'     => $modelType,
                'model_id'       => $modelId,
                'url_path'       => $urlPath,
                'event_name'     => data_get($event, 'event_name'),
                'event_value'    => $eventValue,
                'event_type'     => data_get($event, 'event_type'),
                'event_category' => data_get($event, 'event_category'),
                'ip_address'     => $ip,
                'agent'          => $agent,
                'status'         => data_get($event, 'status', 1),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        return response('Success', 200);
    }

    protected function resolveModelFromUrl(?string $urlPath): array
    {
        if (empty($urlPath)) {
            return [null, null, null];
        }

        // NOTE: UUIDs regex pattern (if url_path contains UUID)
        preg_match('/[0-9a-fA-F-]{36}/', $urlPath, $matches);
        $uuid = $matches[0] ?? null;

        if (empty($uuid)) {
            return [null, null, null];
        }

        if (str_contains($urlPath, '/file/')) {
            return $this->resolveFileModel($uuid);
        }

        if (str_contains($urlPath, '/services/')) {
            return $this->resolveServiceModel($uuid);
        }

        return [null, null, null];
    }

    protected function resolveFileModel(string $uuid): array
    {
        $file = File::find($uuid);

        if ($file) {
            return [File::class, $file->id, $file->type];
        }

        return [null, null, null];
    }

    protected function resolveServiceModel(string $uuid): array
    {
        $service = Service::find($uuid);

        if ($service) {
            return [Service::class, $service->id, $service->name];
        }

        return [null, null, null];
    }
}
