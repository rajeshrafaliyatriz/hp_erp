<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <!-- Left Panel -->
            <div class="col-md-3">
                <h5>My Skills Audit</h5>
                <p>{{ $completedCount ?? '' }} out of {{ $totalSkills ?? '' }} completed ({{ $progress ?? '' }}%)</p>
                                <!-- Pie Chart -->
                <canvas id="progressChart"></canvas>

                <ul class="list-group">
                    <li class="list-group-item active">My Skills ({{ $totalSkills ?? '' }} skills)</li>
                </ul>

<ul class="list-group">
    <li class="list-group-item active d-flex justify-content-between align-items-center">
        My Skills ({{ $totalSkills ?? 0 }} skills)
        <a data-bs-toggle="collapse" href="#skillListCollapse" role="button" aria-expanded="false" aria-controls="skillListCollapse">
            <span class="badge bg-light text-dark rounded-pill">+</span>
        </a>
    </li>
    <div class="collapse" id="skillListCollapse">
        @if(!empty($skills))
            @foreach($skills as $skill)
                <li class="list-group-item">{{ $skill->title }}</li>
            @endforeach
        @else
            <li class="list-group-item text-muted">No skills added.</li>
        @endif
    </div>
</ul>




                
            </div>

            <!-- Skill Assessment Section -->
            <div class="col-md-9">
                <div id="skill-container">
                    @foreach ($skills ?? [] as $skill)
                        <div class="skill-box" data-skill-id="{{ $skill->skill_id }}">
                            <h3>{{ $skill->title }}</h3>
                            <h6>({{ $skill->category }} {{ ($skill->sub_category) ? '/ '.$skill->sub_category : ''}})</h6>
                            <p>{{ $skill->description }}</p>

                            <label>Skill Level:</label>
                            <select class="skill-level">
                                <option value="5">Highly Skilled</option>
                                <option value="4">Developed Skills</option>
                                <option value="3">Competent</option>
                                <option value="2">Basic Capability</option>
                                <option value="1">Very Low</option>
                            </select>

                            <label>Interest Level:</label>
                            <select class="interest-level">
                                <option value="5">Highly Interested</option>
                                <option value="4">Interested</option>
                                <option value="3">Neutral</option>
                                <option value="2">Low Interest</option>
                                <option value="1">Very Low</option>
                            </select>

                            <button class="submit-skill btn btn-primary mt-3">Save & Next</button>
                        </div>
                    @endforeach
                </div>
                @php
                    $levels = [
                        'skill_levels' => [
                            5 => 'Highly Skilled',
                            4 => 'Developed Skills',
                            3 => 'Competent',
                            2 => 'Basic Capability',
                            1 => 'Very Low',
                        ],
                        'interest_levels' => [
                            5 => 'Highly Interested',
                            4 => 'Interested',
                            3 => 'Neutral',
                            2 => 'Low Interest',
                            1 => 'Very Low',
                        ],
                    ];

                @endphp
                @if (isset($userRatedSkills) && count($userRatedSkills) > 0)
                    <div class="usersSkillsRated mt-4">
                        <h2>Your Skills:</h2>
                        <div class="row g-4">
                            @foreach ($userRatedSkills as $key => $item)
                                <div class="col-12 col-sm-6 col-md-4 col-lg-3 mt-4">
                                    <div class="card h-100 border-0" style="box-shadow: #d1e5f9 5px 5px 5px 5px;">
                                        <div class="card-body d-flex flex-column p-2">
                                            <div class="cardHeader" style="display: flex;background:#cff4fc;padding:8px;border-radius:8px;">
                                                <span class="badge bg-primary align-self-start" style="margin-right:6px;border-radius:100%">{{ $key + 1 }}</span>
                                                <h5 class="card-title text-primary fw-bold align-self-start" style="margin:0px !important"> {{ $item['title'] }}</h5>
                                            </div>
                                            <p style="font-size:10px;margin:4px 0px;border-radius:8px;padding:2px;">{{$item['category']}} {{($item['sub_category']) ? '> '.$item['sub_category'] : ''}}</p>
                                            <div class="cardData p-2">
                                            <p class="card-text mb-2" style="font-size:12px;"><strong>Skill Level:</strong>
                                                <span style="background:#d1e7dd;padding:4px;border-radius:8px;white-space: nowrap">{{ $levels['skill_levels'][$item['skill_level']] }}</span></p>
                                            <p class="card-text mb-2" style="font-size:12px;"><strong>Interest Level:</strong>
                                                <span style="background:#fff3cd;padding:4px;border-radius:8px;white-space: nowrap">{{ $levels['interest_levels'][$item['interest_level']] }}</span></p>
                                                </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif


            </div>
        </div>


    </div>
</div>

<!-- JavaScript for transitions & progress -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    $(document).ready(function() {
        let completedCount = {{ $completedCount ?? 0 }};
        let totalSkills = {{ $totalSkills ?? 0 }};

        // Skill fade-in/out logic
        $(".submit-skill").click(function() {
            let skillBox = $(this).closest(".skill-box");
            let skillId = skillBox.data("skill-id");
            let skillLevel = skillBox.find(".skill-level").val();
            let interestLevel = skillBox.find(".interest-level").val();
            let userId = "{{ $data['id'] }}"; // Assuming user is authenticated
            // alert(userId);

            $.ajax({
                url: "{{ route('matrix.save') }}",
                type: "POST",
                data: {
                    _token: "{{ csrf_token() }}",
                    skill_id: skillId,
                    skill_level: skillLevel,
                    interest_level: interestLevel,
                    userId:userId,
                },
                success: function() {
                    completedCount++;
                    updateChart(completedCount, totalSkills);

                    skillBox.fadeOut(500, function() {
                        $(this).next(".skill-box").fadeIn(500);
                    });
                }
            });
        });

        $(".skill-box:not(:first)").hide(); // Hide all except first

        // Pie Chart for progress
        let ctx = document.getElementById('progressChart').getContext('2d');
        let progressChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Remaining'],
                datasets: [{
                    data: [completedCount, totalSkills - completedCount],
                    backgroundColor: ['#28a745', '#ddd']
                }]
            }
        });

        function updateChart(completed, total) {
            progressChart.data.datasets[0].data = [completed, total - completed];
            progressChart.update();
        }
    });
</script>
