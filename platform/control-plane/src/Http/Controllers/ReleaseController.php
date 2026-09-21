<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Request;
use Dilibori\ControlPlane\Http\Response;
use Dilibori\ControlPlane\Support\Uuid;

final class ReleaseController
{
    public function __construct(private Application $app)
    {
    }

    public function index(): Response
    {
        $rows = $this->app->db()->query(
            'SELECT id, image_digest, image_tag, notes, created_at FROM releases ORDER BY created_at DESC',
        )->fetchAll();

        return Response::json(['releases' => $rows]);
    }

    public function store(Request $request): Response
    {
        $digest = trim((string) $request->input('image_digest', ''));
        $tag = trim((string) $request->input('image_tag', ''));
        $notes = trim((string) $request->input('notes', ''));

        if ($digest === '' || $tag === '') {
            return Response::error('image_digest and image_tag are required');
        }

        $id = Uuid::v4();
        $statement = $this->app->db()->prepare(
            'INSERT INTO releases (id, image_digest, image_tag, notes)
             VALUES (:id, :image_digest, :image_tag, :notes)
             RETURNING id, image_digest, image_tag, notes, created_at',
        );
        $statement->execute([
            'id' => $id,
            'image_digest' => $digest,
            'image_tag' => $tag,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        $release = $statement->fetch();
        $this->app->audit()->log('release.created', null, null, null, [
            'release_id' => $release['id'],
            'image_tag' => $tag,
        ]);

        return Response::json(['release' => $release], 201);
    }
}
