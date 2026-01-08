<?php

namespace App\Http\Controllers;

use App\Models\LmsDataContentNeo4j;
use App\Services\Neo4jService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OrganizationGraphController extends Controller
{
     protected $neo4jService;

    public function __construct(Neo4jService $neo4jService)
    {
        $this->neo4jService = $neo4jService;
    }

    public function show($orgId)
    {
        $client = $this->neo4jService->getClient();

        $result = $client->run(
            'MATCH (o:Organization {orgId: $orgId})
            OPTIONAL MATCH (d:Department)-[r1:BELONGS_TO_ORG]->(o)
            OPTIONAL MATCH (d)-[r2:HAS_PARENT]->(parent:Department)
            OPTIONAL MATCH (j:JobRole)-[r3:IS_IN_DEPT]->(d)
            OPTIONAL MATCH (j)-[r4:BELONGS_TO_ORG]->(o)
            RETURN o, d, parent, j, r1, r2, r3, r4;',
            ['orgId' => is_numeric($orgId) ? (int)$orgId : $orgId]
        );

        $nodes = [];
        $relationships = [];
        $rootNode = null;

        foreach ($result as $record) {
            $o = $record->get('o');
            if (!$rootNode) {
                $rootNode = $this->formatNode($o);          
                $nodes[$rootNode['id']] = $rootNode;
            }

            if ($record->get('d')) {
                $node = $this->formatNode($record->get('d'));
                $nodes[$node['id']] = $node;
            }
            if ($record->get('parent')) {
                $node = $this->formatNode($record->get('parent'));
                $nodes[$node['id']] = $node;
            }

            if ($record->get('j')) {
                $node = $this->formatNode($record->get('j'));
                $nodes[$node['id']] = $node;
            }

            if ($record->get('r1')) {
                $relationships[] = $this->formatRelationship($record->get('r1'));
            }
            if ($record->get('r2')) {
                $relationships[] = $this->formatRelationship($record->get('r2'));
            }
            if ($record->get('r3')) {
                $relationships[] = $this->formatRelationship($record->get('r3'));
            }
            if ($record->get('r4')) {
                $relationships[] = $this->formatRelationship($record->get('r4'));
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

