<?php

declare(strict_types=1);

namespace ModpackSwitcher\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use ModpackSwitcher\Services\FtbCatalog;
use Pterodactyl\Models\Server;

class FtbController
{
    public function versions(Request $request, Server $server, string $project, \ModpackSwitcher\Services\FtbRelease $releases): JsonResponse
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,9}$/', $project), 422, 'Invalid FTB project ID.');
        return new JsonResponse($releases->versions($project));
    }

    public function search(Request $request, Server $server, FtbCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'minecraft' => ['nullable', 'string', 'max:30', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'loader' => ['nullable', Rule::in(['forge', 'fabric', 'neoforge', 'quilt'])],
            'sort' => ['required', Rule::in(['downloads', 'relevance', 'updated', 'newest'])],
            'page' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
        return new JsonResponse($catalog->search(trim($data['query'] ?? ''), $data['minecraft'] ?? '', $data['loader'] ?? '', $data['sort'], (int) $data['page']));
    }
}
