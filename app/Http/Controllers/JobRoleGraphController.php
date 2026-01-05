<?php

namespace App\Http\Controllers;

use App\Models\LmsDataContentNeo4j;
use App\Services\Neo4jService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class JobRoleGraphController extends Controller
{
    protected $neo4jService;

    public function __construct(Neo4jService $neo4jService)
    {
        $this->neo4jService = $neo4jService;
    }

    public function show($jobRoleId)
{
    $client = $this->neo4jService->getClient();

    $result = $client->run(
        'MATCH (jr:JobRole {jobRoleId: $jobRoleId})
         OPTIONAL MATCH (jr)-[r1]->(n1)
         OPTIONAL MATCH (n1)-[r2]->(n2)
         OPTIONAL MATCH (jr)-[rs:REQUIRES_SKILL]->(s:Skill)
         RETURN jr, r1, n1, r2, n2, rs, s;',
        ['jobRoleId' => (int) $jobRoleId]
    );

    $nodes = [];
    $relationships = [];
    $rootNode = null;

    foreach ($result as $record) {

        /* Root JobRole */
        if ($record->get('jr') && !$rootNode) {
            $rootNode = $this->formatNode($record->get('jr'));
            $nodes[$rootNode['id']] = $rootNode;
        }

        /* First-level node */
        if ($record->get('n1')) {
            $node = $this->formatNode($record->get('n1'));
            $nodes[$node['id']] = $node;
        }

        /* Second-level node */
        if ($record->get('n2')) {
            $node = $this->formatNode($record->get('n2'));
            $nodes[$node['id']] = $node;
        }

        /* ✅ Skill node */
        if ($record->get('s')) {
            $node = $this->formatNode($record->get('s'));
            $nodes[$node['id']] = $node;
        }

        /* Relationships */
        foreach (['r1', 'r2', 'rs'] as $relKey) {
            if ($record->get($relKey)) {
                $rel = $record->get($relKey);
                $relationships[$rel->getId()] = $this->formatRelationship($rel);
            }
        }
    }

    return response()->json([
        'rootNode' => $rootNode,
        'nodes' => array_values($nodes),
        'relationships' => array_values($relationships)
    ]);
}


    private function formatNode($node)
    {
        return [
            'id' => $node->getId(),
            'labels' => $node->getLabels(),
            'properties' => $node->getProperties()
        ];
    }

    private function formatRelationship($rel)
    {
        return [
            'id' => $rel->getId(),
            'type' => $rel->getType(),
            'startNode' => $rel->getStartNodeId(),
            'endNode' => $rel->getEndNodeId(),
            'properties' => $rel->getProperties()
        ];
    }
}
