<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CompetencyDashboardController extends Controller
{
    public function index()
    {
        $data = [
            "summary" => [
	            "total_roles" => 247,
	            "departments" => 12,
	            "mapped_tasks" => 1834,
	            "completion_rate" => "87%",
	            "skill_coverage" => 92,
	            "skills_mapped" => "2.1k skills",
	            "risk_score" => 68,
	            "future_ready" => 74,
	            "active_reviews" => 23,
	            "trend" => [
	                "roles" => "+4% vs last month",
	                "tasks" => "+3% vs last month",
	                "skills" => "+2% vs last month",
	                "risk" => "-1% vs last month",
	                "future_ready" => "+5% vs last quarter",
	            ],
        	],
            "tabs" => [
                "balance_equity" => [
                    "workload_equity_heatmap" => [
			            ["role" => "Senior Developer", "workload_index" => 92, "tasks" => 45, "level" => "Critical"],
			            ["role" => "DevOps Engineer", "workload_index" => 84, "tasks" => 41, "level" => "High"],
			            ["role" => "Product Manager", "workload_index" => 78, "tasks" => 32, "level" => "High"],
			            ["role" => "UX Designer", "workload_index" => 71, "tasks" => 28, "level" => "High"],
			            ["role" => "Data Analyst", "workload_index" => 62, "tasks" => 25, "level" => "Medium"],
			            ["role" => "Marketing Lead", "workload_index" => 68, "tasks" => 30, "level" => "High"],
			            ["role" => "HR Specialist", "workload_index" => 48, "tasks" => 20, "level" => "Medium"],
			            ["role" => "Finance Manager", "workload_index" => 54, "tasks" => 21, "level" => "Medium"],
			        ],
                    "task_risk_analysis" => [
			            "description" => "High risk, low coverage tasks require immediate attention. Bubble size indicates task criticality.",
			            "tasks" => [
			                ["id" => 1, "department" => "Engineering", "coverage" => 65, "risk" => 80, "criticality" => "High"],
			                ["id" => 2, "department" => "Product", "coverage" => 72, "risk" => 70, "criticality" => "Medium"],
			                ["id" => 3, "department" => "Finance", "coverage" => 85, "risk" => 40, "criticality" => "Low"],
			                ["id" => 4, "department" => "Design", "coverage" => 60, "risk" => 75, "criticality" => "High"],
			                ["id" => 5, "department" => "HR", "coverage" => 78, "risk" => 55, "criticality" => "Medium"],
			            ]
			        ],
                    "role_similarity_network" => [
			            "threshold" => 50,
			            "departments" => [
			                "HR", "Finance", "Sales", "Product", "Engineering", "Marketing", "Design", "Operations"
			            ],
			            "connections" => [
			                ["source" => "Senior Developer", "target" => "DevOps Engineer", "similarity" => 85],
			                ["source" => "Product Manager", "target" => "UX Designer", "similarity" => 78],
			                ["source" => "Data Analyst", "target" => "Finance Manager", "similarity" => 65],
			                ["source" => "Marketing Lead", "target" => "Product Manager", "similarity" => 70],
			                ["source" => "HR Specialist", "target" => "Operations Lead", "similarity" => 60],
			            ],
			        ],
                    "coverage_scorecards" => [
			            "overall" => 80,
			            "details" => [
			                ["metric" => "Roles with Mapped Tasks", "score" => 87, "target" => 95],
			                ["metric" => "Tasks with Mapped Skills", "score" => 92, "target" => 95],
			                ["metric" => "Skills with Proficiency Levels", "score" => 74, "target" => 85],
			                ["metric" => "Attitudes/Behavior Mapped", "score" => 45, "target" => 70],
			                ["metric" => "External Framework Alignment", "score" => 89, "target" => 90],
			                ["metric" => "Competency Documentation", "score" => 91, "target" => 95],
			            ],
			            "summary" => [
			                "on_track" => 2,
			                "at_risk" => 3,
			                "critical" => 1,
			                "target" => "Improve to 90%",
			            ]
			        ]
                ],
                "health_completeness" => [

            // 🧭 Competency Health Radar
            "competency_health_radar" => [
                "task_mapping" => [
                    "current" => 80,
                    "target" => 95,
                    "label" => "Task Mapping"
                ],
                "skill_coverage" => [
                    "current" => 92,
                    "target" => 95,
                    "label" => "Skill Coverage"
                ],
                "behavior_mapping" => [
                    "current" => 45,
                    "target" => 70,
                    "label" => "Behavior Mapping"
                ],
                "external_alignment" => [
                    "current" => 89,
                    "target" => 90,
                    "label" => "External Framework Alignment"
                ],
                "risk_assessment" => [
                    "current" => 68,
                    "target" => 80,
                    "label" => "Risk Assessment"
                ],
                "future_readiness" => [
                    "current" => 74,
                    "target" => 90,
                    "label" => "Future Readiness"
                ],
            ],

            // 📊 Skills Management Funnel
            "skills_management_funnel" => [
                "identified_orphans" => 10000,
                "in_review" => 7000,
                "mapped_candidates" => 3000,
                "approved_integrated" => 2000,
                "completion_rate" => "20%",
                "summary" => [
                    "description" => "Represents the conversion rate of identified skills progressing through review, mapping, and final approval.",
                    "trend" => "+4% vs last month"
                ]
            ],

            // 🧩 Additional Metrics
            "health_summary" => [
                "overall_health_score" => 76,
                "improvement_areas" => [
                    "behavior_mapping",
                    "risk_assessment",
                    "future_readiness"
                ],
                "strength_areas" => [
                    "skill_coverage",
                    "external_alignment"
                ],
                "comments" => "Overall competency mapping is strong. Focus areas include behavioral assessment and future readiness improvements."
            ]
        ],
                "alignment_standardization" => [

            // 🌐 External Benchmark Alignment
            "external_benchmark_alignment" => [
                "framework" => "O*NET v28.0",
                "alignment_percent" => 72,
                "aligned" => 145,
                "partial" => 52,
                "not_aligned" => 28,
                "total_competencies" => 225,
                "based_on" => "197 / 225 competencies matched",
                "category_distribution" => [
                    "low" => "0–50%",
                    "partial" => "50–75%",
                    "strong" => "75–100%"
                ],
                "last_update" => "This Quarter",
                "comparison_trend" => "+5% vs last quarter",
                "status" => "Moderate Alignment",
                "color_scale" => [
                    "low" => "#FF6B6B",
                    "partial" => "#FFD93D",
                    "strong" => "#4CAF50"
                ],
                "breakdown_url" => "/alignment/coverage-breakdown"
            ],

            // 🧩 Coverage Breakdown (optional)
            "coverage_breakdown" => [
                "framework" => "O*NET v28.0",
                "aligned" => [
                    "count" => 145,
                    "examples" => [
                        "Software Development Principles",
                        "Data Analysis & Visualization",
                        "Project Planning & Coordination"
                    ]
                ],
                "partial" => [
                    "count" => 52,
                    "examples" => [
                        "Strategic Communication",
                        "Customer Engagement",
                        "Risk Compliance"
                    ]
                ],
                "not_aligned" => [
                    "count" => 28,
                    "examples" => [
                        "AI Readiness",
                        "Behavioral Agility",
                        "Innovation Management"
                    ]
                ]
            ],

            // 📊 Alignment Summary
            "alignment_summary" => [
                "overall_alignment" => 72,
                "target_alignment" => 85,
                "gap" => "-13%",
                "remarks" => "Most competencies show strong external alignment with O*NET standards. Improvement is needed in behavior and AI-readiness categories."
            ]
        ],
		
		"stakeholder_dashboard" => [

            // 📈 Competency Density Section
            "competency_density" => [
                "fastest_growing_role" => [
                    "role_name" => "Data Scientist",
                    "growth_percent" => "+45%",
                    "category" => "Technology"
                ],
                "average_growth_top10" => "+12%",
                "total_roles_analyzed" => 127,
                "projection_horizon" => [
                    "year" => 2026,
                    "period" => "3 years ahead"
                ],
                "role_rankings" => [
                    [
                        "rank" => 1,
                        "role" => "Data Scientist",
                        "category" => "Technology",
                        "density_score" => 262,
                        "growth" => "+45.2%"
                    ],
                    [
                        "rank" => 2,
                        "role" => "Product Manager",
                        "category" => "Business",
                        "density_score" => 166,
                        "growth" => "+18.7%"
                    ],
                    [
                        "rank" => 3,
                        "role" => "Software Engineer",
                        "category" => "Technology",
                        "density_score" => 167,
                        "growth" => "+12.4%"
                    ],
                    [
                        "rank" => 4,
                        "role" => "UX Designer",
                        "category" => "Design",
                        "density_score" => 148,
                        "growth" => "+8.9%"
                    ],
                    [
                        "rank" => 5,
                        "role" => "DevOps Engineer",
                        "category" => "Technology",
                        "density_score" => 143,
                        "growth" => "+7.3%"
                    ]
                ],
                "trend_summary" => [
                    "note" => "Projections for 2025–2026 are based on historical trends and market analysis. Actual values may vary based on industry changes and organizational needs.",
                    "trend_chart" => [
                        "labels" => ["2021", "2022", "2023", "2024", "2025", "2026"],
                        "datasets" => [
                            [
                                "role" => "Data Scientist",
                                "values" => [140, 175, 200, 230, 260, 285]
                            ],
                            [
                                "role" => "DevOps Engineer",
                                "values" => [130, 140, 155, 165, 175, 190]
                            ],
                            [
                                "role" => "Product Manager",
                                "values" => [120, 135, 145, 155, 165, 170]
                            ],
                            [
                                "role" => "Software Engineer",
                                "values" => [125, 145, 160, 170, 180, 195]
                            ],
                            [
                                "role" => "UX Designer",
                                "values" => [110, 125, 135, 145, 155, 165]
                            ]
                        ]
                    ]
                ],
                "growth_categories" => [
                    "rising" => "≥5% growth",
                    "stable" => "0–5% growth",
                    "declining" => "<0% growth"
                ]
            ],

            // 🔥 Skills Heatmap Tab (Placeholder Example)
            "skills_heatmap" => [
                "description" => "Displays skill density and adoption trends across departments.",
                "departments" => [
                    [
                        "name" => "Technology",
                        "skills_mapped" => 220,
                        "coverage" => "95%",
                        "critical_gaps" => 3
                    ],
                    [
                        "name" => "Design",
                        "skills_mapped" => 140,
                        "coverage" => "88%",
                        "critical_gaps" => 5
                    ],
                    [
                        "name" => "Operations",
                        "skills_mapped" => 100,
                        "coverage" => "80%",
                        "critical_gaps" => 7
                    ]
                ]
            ],

            // ⚠️ Skills Gap Tab (Placeholder Example)
            "skills_gap" => [
                "total_skills_assessed" => 2100,
                "skills_with_gaps" => 340,
                "gap_percentage" => 16,
                "top_gap_roles" => [
                    [
                        "role" => "Product Manager",
                        "gap" => "Leadership Communication",
                        "impact" => "High"
                    ],
                    [
                        "role" => "Data Analyst",
                        "gap" => "Data Storytelling",
                        "impact" => "Medium"
                    ],
                    [
                        "role" => "UX Designer",
                        "gap" => "Accessibility Standards",
                        "impact" => "Medium"
                    ]
                ],
                "recommendation" => "Prioritize reskilling in communication, storytelling, and accessibility-focused design skills over the next quarter."
            ]
        ]
            ]
        ];

        return response()->json($data);
    }
}
