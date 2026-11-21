<?php

declare(strict_types=1);

return [
    'routes' => [

        // ─── Gallery ──────────────────────────────────────────────────────────
        [
            'name' => 'gallery#index',
            'url'  => '/',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#folder',
            'url'  => '/folder/{path}',
            'verb' => 'GET',
            'requirements' => ['path' => '.+'],
        ],
        [
            'name' => 'gallery#images',
            'url'  => '/api/images',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#thumbnail',
            'url'  => '/api/thumbnail/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#preview',
            'url'  => '/api/preview/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#download',
            'url'  => '/api/download/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'gallery#downloadZip',
            'url'  => '/api/download-zip',
            'verb' => 'GET',
        ],

        // ─── Rating ───────────────────────────────────────────────────────────
        [
            'name' => 'rating#get',
            'url'  => '/api/rating/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'rating#setBatch',
            'url'  => '/api/rating/batch',
            'verb' => 'POST',
        ],
        [
            'name' => 'rating#set',
            'url'  => '/api/rating/{fileId}',
            'verb' => 'POST',
        ],
        [
            'name' => 'rating#delete',
            'url'  => '/api/rating/{fileId}',
            'verb' => 'DELETE',
        ],

        // ─── Share ────────────────────────────────────────────────────────────
        [
            'name' => 'share#create',
            'url'  => '/api/share',
            'verb' => 'POST',
        ],
        [
            'name' => 'share#list',
            'url'  => '/api/share',
            'verb' => 'GET',
        ],
        // Log-Routen VOR {token}-Wildcard (spezifischer zuerst)
        [
            'name' => 'share#getLog',
            'url'  => '/api/share/{token}/log',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#deleteLog',
            'url'  => '/api/share/{token}/log',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'share#get',
            'url'  => '/api/share/{token}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#update',
            'url'  => '/api/share/{token}',
            'verb' => 'PUT',
        ],
        [
            'name' => 'share#delete',
            'url'  => '/api/share/{token}',
            'verb' => 'DELETE',
        ],

        // ─── Guest (öffentlicher Link ohne Login) ─────────────────────────────
        [
            'name' => 'share#guestView',
            'url'  => '/guest/{token}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestImages',
            'url'  => '/api/guest/{token}/images',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestThumbnail',
            'url'  => '/api/guest/{token}/thumbnail/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestPreview',
            'url'  => '/api/guest/{token}/preview/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestDownload',
            'url'  => '/api/guest/{token}/download/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestDownloadZip',
            'url'  => '/api/guest/{token}/download-zip',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestRate',
            'url'  => '/api/guest/{token}/rate',
            'verb' => 'POST',
        ],
        [
            'name' => 'share#guestVerifyPassword',
            'url'  => '/api/guest/{token}/verify',
            'verb' => 'POST',
        ],
        [
            'name' => 'share#guestAcknowledgeMessage',
            'url'  => '/api/guest/{token}/message/ack',
            'verb' => 'POST',
        ],

        // ─── Kommentare (Owner) ───────────────────────────────────────────────
        [
            'name' => 'rating#getComment',
            'url'  => '/api/rating/{fileId}/comment',
            'verb' => 'GET',
        ],
        [
            'name' => 'rating#saveComment',
            'url'  => '/api/rating/{fileId}/comment',
            'verb' => 'POST',
        ],
        [
            'name' => 'rating#deleteComment',
            'url'  => '/api/rating/{fileId}/comment',
            'verb' => 'DELETE',
        ],

        // ─── Kommentare (Gast) ────────────────────────────────────────────────
        [
            'name' => 'share#guestGetComment',
            'url'  => '/api/guest/{token}/comment/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'share#guestSaveComment',
            'url'  => '/api/guest/{token}/comment',
            'verb' => 'POST',
        ],
        [
            'name' => 'share#guestDeleteComment',
            'url'  => '/api/guest/{token}/comment/{fileId}',
            'verb' => 'DELETE',
        ],

        // ─── Settings ─────────────────────────────────────────────────────────
        [
            'name' => 'settings#getSettings',
            'url'  => '/api/settings',
            'verb' => 'GET',
        ],
        [
            'name' => 'settings#saveSettings',
            'url'  => '/api/settings',
            'verb' => 'POST',
        ],
    ],
];
