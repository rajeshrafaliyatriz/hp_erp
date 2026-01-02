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
            RETURN jr, r1, n1, r2, n2;',
            ['jobRoleId' => is_numeric($jobRoleId) ? (int)$jobRoleId : $jobRoleId]
        );

        $nodes = [];
        $relationships = [];
        $rootNode = null;

        foreach ($result as $record) {
            $jr = $record->get('jr');
            if (!$rootNode) {
                $rootNode = $this->formatNode($jr);
                $nodes[$rootNode['id']] = $rootNode;
            }

            if ($record->get('n1')) {
                $node = $this->formatNode($record->get('n1'));
                $nodes[$node['id']] = $node;
            }
            if ($record->get('n2')) {
                $node = $this->formatNode($record->get('n2'));
                $nodes[$node['id']] = $node;
            }

            if ($record->get('r1')) {
                $relationships[] = $this->formatRelationship($record->get('r1'));   
            }
            if ($record->get('r2')) {
                $relationships[] = $this->formatRelationship($record->get('r2'));   
            }
        }

        return response()->json([
            'rootNode' => $rootNode,
            'nodes' => array_values($nodes),
            'relationships' => $relationships
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
