<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Request;
use Dilibori\ControlPlane\Http\Response;
use Dilibori\ControlPlane\Models\Cell;
use Dilibori\ControlPlane\Support\Uuid;
use PDO;

final class CellController
{
    private PDO $db;

    public function __construct(private Application $app)
    {
        $this->db = $app->db();
    }

    public function index(): Response
    {
        $rows = $this->db->query(
            'SELECT id, name, provider, capacity_metadata, agent_url, schedulable, created_at
             FROM cells
             ORDER BY name',
        )->fetchAll();

        return Response::json([
            'cells' => array_map(static fn (array $row) => Cell::toPublic(Cell::fromRow($row)), $rows),
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $provider = trim((string) $request->input('provider', 'docker'));
        $agentUrl = trim((string) $request->input('agent_url', ''));
        $capacity = $request->input('capacity_metadata', []);
        $schedulable = $request->input('schedulable', true);

        if ($name === '') {
            return Response::error('name is required');
        }

        if ($agentUrl === '') {
            return Response::error('agent_url is required');
        }

        if (!is_array($capacity)) {
            return Response::error('capacity_metadata must be an object');
        }

        if (!array_key_exists('max_deployments', $capacity)) {
            $capacity['max_deployments'] = 16;
        }

        $id = Uuid::v4();
        $statement = $this->db->prepare(
            'INSERT INTO cells (id, name, provider, capacity_metadata, agent_url, schedulable)
             VALUES (:id, :name, :provider, :capacity_metadata::jsonb, :agent_url, :schedulable)
             RETURNING id, name, provider, capacity_metadata, agent_url, schedulable, created_at',
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'provider' => $provider !== '' ? $provider : 'docker',
            'capacity_metadata' => json_encode($capacity, JSON_THROW_ON_ERROR),
            'agent_url' => rtrim($agentUrl, '/'),
            'schedulable' => $this->asBool($schedulable) ? 't' : 'f',
        ]);

        $cell = Cell::toPublic(Cell::fromRow($statement->fetch()));
        $this->app->audit()->log('cell.created', null, null, null, [
            'cell_id' => $cell['id'],
            'name' => $cell['name'],
        ]);

        return Response::json(['cell' => $cell], 201);
    }

    public function update(Request $request): Response
    {
        $cell = $this->findCell($request->routeParams['id']);
        if ($cell === null) {
            return Response::error('Cell not found', 404);
        }

        $agentUrl = $request->input('agent_url');
        $schedulable = $request->input('schedulable');
        $capacity = $request->input('capacity_metadata');

        if ($agentUrl === null && $schedulable === null && $capacity === null) {
            return Response::error('Provide agent_url, schedulable, and/or capacity_metadata');
        }

        if ($capacity !== null && !is_array($capacity)) {
            return Response::error('capacity_metadata must be an object');
        }

        $nextAgent = $agentUrl !== null ? rtrim(trim((string) $agentUrl), '/') : ($cell['agent_url'] ?? null);
        $nextSchedulable = $schedulable !== null ? $this->asBool($schedulable) : (bool) $cell['schedulable'];
        $nextCapacity = $capacity ?? ($cell['capacity_metadata'] ?? []);

        $statement = $this->db->prepare(
            'UPDATE cells
             SET agent_url = :agent_url,
                 schedulable = :schedulable,
                 capacity_metadata = :capacity_metadata::jsonb
             WHERE id = :id
             RETURNING id, name, provider, capacity_metadata, agent_url, schedulable, created_at',
        );
        $statement->execute([
            'id' => $cell['id'],
            'agent_url' => $nextAgent === '' ? null : $nextAgent,
            'schedulable' => $nextSchedulable ? 't' : 'f',
            'capacity_metadata' => json_encode($nextCapacity, JSON_THROW_ON_ERROR),
        ]);

        $updated = Cell::toPublic(Cell::fromRow($statement->fetch()));
        $this->app->audit()->log('cell.updated', null, null, null, [
            'cell_id' => $updated['id'],
            'schedulable' => $updated['schedulable'],
        ]);

        return Response::json(['cell' => $updated]);
    }

    public function drain(Request $request): Response
    {
        $sourceId = $request->routeParams['id'];
        $targetCellId = trim((string) $request->input('target_cell_id', ''));
        if ($targetCellId === '') {
            return Response::error('target_cell_id is required');
        }

        try {
            $result = $this->app->migration()->drain($sourceId, $targetCellId, $this->actor($request));

            return Response::json($result);
        } catch (\Throwable $exception) {
            $code = str_contains(strtolower($exception->getMessage()), 'not found') ? 404 : 400;

            return Response::error($exception->getMessage(), $code);
        }
    }

    /** @return array<string, mixed>|null */
    private function findCell(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, provider, capacity_metadata, agent_url, schedulable, created_at
             FROM cells WHERE id = :id OR name = :name',
        );
        $statement->execute(['id' => $id, 'name' => $id]);
        $row = $statement->fetch();

        return $row ? Cell::fromRow($row) : null;
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 't'], true);
        }

        return (bool) $value;
    }

    private function actor(Request $request): string
    {
        $header = $_SERVER['HTTP_X_ACTOR'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return (string) $this->app->config()['default_actor'];
    }
}
