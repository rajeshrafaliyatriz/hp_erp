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

    /**
     * One job role's competency graph.
     *
     * Previously a single query chaining seven independent OPTIONAL MATCHes
     * off the same :JobRole. Independent OPTIONAL MATCHes compose as nested
     * loops, so their row counts multiply rather than add: a role with 189
     * REQUIRES_SKILL edges (real data — "Chief Executives") times its
     * behaviour/knowledge/ability/attitude fan-out exhausted PHP's 512MB
     * limit outright. That is why this endpoint had zero working frontend
     * callers despite being fully wired. Each relationship family is now its
     * own small, correlated query instead.
     */
    public function show($jobRoleId)
{
    $client = $this->neo4jService->getClient();
    $jobRoleId = (int) $jobRoleId;

    $root = $client->run(
        'MATCH (jr:JobRole {jobRoleId: $jobRoleId}) RETURN jr',
        ['jobRoleId' => $jobRoleId]
    )->first();

    if (! $root || ! $root->get('jr')) {
        return response()->json(['rootNode' => null, 'nodes' => [], 'relationships' => []]);
    }

    $rootNode = $this->formatNode($root->get('jr'));
    $nodes = [$rootNode['id'] => $rootNode];
    $relationships = [];
    $skillIds = [];

    // REQUIRES_ATTITUTE (not ...ATTITUDE) is the relationship type actually
    // written to the graph by whatever projector created it — matching the
    // correctly-spelled name silently returns zero rows for every job role
    // (confirmed against live data: 984 real edges, all under this
    // spelling). Match reality, don't "fix" it back to a typo that breaks it.
    $families = [
        ['rel' => 'BELONGS_TO_ORG', 'target' => null],
        ['rel' => 'IS_IN_DEPT', 'target' => null],
        ['rel' => 'HAS_PARENT', 'target' => null],
        ['rel' => 'REQUIRES_SKILL', 'target' => 'Skill', 'limit' => 300],
        ['rel' => 'REQUIRES_KNOWLEDGE', 'target' => 'Knowledge', 'limit' => 100],
        ['rel' => 'REQUIRES_ABILITY', 'target' => 'Ability', 'limit' => 100],
        ['rel' => 'REQUIRES_ATTITUTE', 'target' => 'Attitude', 'limit' => 100],
    ];

    foreach ($families as $family) {
        $targetMatch = $family['target'] ? ':'.$family['target'] : '';
        $limit = $family['limit'] ?? 20;

        $result = $client->run(
            "MATCH (jr:JobRole {jobRoleId: \$jobRoleId})-[r:{$family['rel']}]->(n{$targetMatch})
             RETURN r, n LIMIT {$limit}",
            ['jobRoleId' => $jobRoleId]
        );

        foreach ($result as $record) {
            $rawNode = $record->get('n');
            $node = $this->formatNode($rawNode);
            $nodes[$node['id']] = $node;

            if ($family['rel'] === 'REQUIRES_SKILL') {
                $skillIds[] = $rawNode->getId();
            }

            $rel = $record->get('r');
            $relationships[$rel->getId()] = $this->formatRelationship($rel);
        }
    }

    // Behaviours hang off each Skill, not off the job role directly. Pulling
    // them per already-loaded skill (one query, filtered by id) is the same
    // decorrelation as above — chaining this as a third OPTIONAL MATCH onto
    // the skill fan-out is exactly the pattern that caused the blow-up.
    if ($skillIds !== []) {
        $result = $client->run(
            'MATCH (s:Skill)-[r:REQUIRES_BEHAVIOUR]->(b:Behaviour)
             WHERE id(s) IN $skillIds
             RETURN r, b LIMIT 300',
            ['skillIds' => $skillIds]
        );

        foreach ($result as $record) {
            $node = $this->formatNode($record->get('b'));
            $nodes[$node['id']] = $node;
            $rel = $record->get('r');
            $relationships[$rel->getId()] = $this->formatRelationship($rel);
        }
    }

    return response()->json([
        'rootNode' => $rootNode,
        'nodes' => array_values($nodes),
        'relationships' => array_values($relationships),
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
