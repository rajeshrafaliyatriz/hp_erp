<?php

namespace App\Http\Controllers;

use App\Models\LmsDataContentNeo4j;
use App\Services\Neo4jService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class Neo4jSyncController extends Controller
{
    protected $neo4jService;

    public function __construct(Neo4jService $neo4jService)
    {
        $this->neo4jService = $neo4jService;
    }

    public function sync(): JsonResponse
    {
        // Fetched data from MySQL
        $data = LmsDataContentNeo4j::all();
        Log::info($data);
        foreach ($data as $item) {
            // Created organization node
            $query = 'MERGE (section:Organization {Organization: $Organization}) RETURN section';
            $this->neo4jService->getClient()->run($query, [
                'Organization' => $item->Organization,
            ]);

            // Created standard node
            $query = 'MERGE (Departments:Departments {Departments: $Departments}) RETURN Departments';
            $this->neo4jService->getClient()->run($query, [
                'Departments' => $item->Departments,
            ]);

            // Created subject node
            $query = 'MERGE (JobRoles:JobRoles {JobRoles: $JobRoles}) RETURN JobRoles';
            $this->neo4jService->getClient()->run($query, [
                'JobRoles' => $item->JobRoles,
            ]);

            // Created relationships: academic_section -> standard -> subject -> chapter
            $query = '
                MATCH (section:Organization {Organization: $Organization}),
                      (Departments:Departments {Departments: $Departments})
                MERGE (section)-[:OFFERS]->(Departments)';
            $this->neo4jService->getClient()->run($query, [
                'Organization' => $item->Organization,
                'Departments'         => $item->Departments,
            ]);

            $query = '
                MATCH (Departments:Departments {Departments: $Departments}),
                      (JobRoles:JobRoles {JobRoles: $JobRoles})
                MERGE (Departments)-[:OFFERS]->(JobRoles)';
            $this->neo4jService->getClient()->run($query, [
                'Departments' => $item->Departments,
                'JobRoles'  => $item->JobRoles,
            ]);
        }

        return response()->json(['status' => 'Data synced to Neo4j']);
    }
}
