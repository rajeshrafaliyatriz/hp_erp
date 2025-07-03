<div id="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <!-- Left Panel -->
            <div class="col-md-3">
                <h5>My Skills Audit</h5>
                <p>{{ $completedCount ?? '' }} out of {{ $totalSkills ?? '' }} completed </p>
                <!-- Pie Chart -->
                <canvas id="progressChart"></canvas>

                <ul class="list-group">
                    <li class="list-group-item active d-flex justify-content-between align-items-center">
                        My Skills ({{ $totalSkills ?? 0 }} skills)
                        <a data-bs-toggle="collapse" href="#skillListCollapse" role="button" aria-expanded="false"
                            aria-controls="skillListCollapse">
                            <span class="badge bg-light rounded-pill">+</span>
                        </a>
                    </li>
                    <div class="collapse" id="skillListCollapse">
                        @if (!empty($skills))
                            @foreach ($jobroleSkills as $skill)
                                <li class="list-group-item">☛ {{ $skill->skill }}</li>
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
                            <div class="col-md-12">
                                <h3>{{ $skill->title }}</h3>
                                <h6>({{ $skill->category }}
                                    {{ $skill->sub_category ? '/ ' . $skill->sub_category : '' }})
                                </h6>
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
                                <hr>
                            </div>
                            <div class="col-md-12" style="display:flex;justify-content:space-between">
                                <div class="knowledgeDiv" style="width:50%;padding:0px 4px;">
                                    <div class="subDiv" style="border:1px solid #ddd;padding:4px;border-radius:10px;">
                                        <h3>Knowledge</h3>
                                        <hr style="margin:0px !important">
                                        @if (is_array($skill->knowledge))
                                            @foreach ($skill->knowledge as $ke => $k)
                                                <input type="hidden" name="knowledge[{{ $k }}]"
                                                    id="knowledgeInput_{{ $ke }}" class="knowledge-input" data-key="{{ $k }}" value="">
                                                <ul style="padding: 4px 8px; border-bottom: 2px solid #09352b55;">
                                                    <li style="padding:2px;">{{ $k }}</li>
                                                    <li>
                                                        @for ($i = 1; $i <= 5; $i++)
                                                            <span class="mdi mdi-star-outline knowledgeStar{{$ke}}" style="font-size:18px;"
                                                                onclick="AKRating('knowledgeInput_{{ $ke }}','knowledge','{{ $i }}','{{$ke}}');"  id="knowledgeStar{{$ke}}_{{$i}}"></span>
                                                        @endfor
                                                    </li>
                                                </ul>
                                            @endforeach
                                        @else
                                            <p class="text-muted">No knowledge items.</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="abilityDiv" style="width:50%;padding:0px 4px;">
                                    <div class="subDiv" style="border:1px solid #ddd;padding:4px;border-radius:10px;">
                                        <h3>Ability</h3>
                                        <hr style="margin:0px !important">
                                        @if (is_array($skill->ability))
                                            @foreach ($skill->ability as $ke => $k)
                                                <input type="hidden" name="ability[{{ $k }}]"
                                                    id="AbilityInput_{{ $ke }}" class="ability-input" data-key="{{ $k }}" value="">
                                                <ul style="padding: 4px 8px;border-bottom: 2px solid #09352b55;">
                                                    <li style="padding:2px;">{{ $k }}</li>
                                                    <li>
                                                        @for ($i = 1; $i <= 5; $i++)
                                                            <span class="mdi mdi-star-outline abilityStar{{$ke}}" style="font-size:18px;"
                                                                onclick="AKRating('AbilityInput_{{ $ke }}','ability','{{ $i }}','{{$ke}}');" id="abilityStar{{$ke}}_{{$i}}"></span>
                                                        @endfor
                                                    </li>
                                                </ul>
                                            @endforeach
                                        @else
                                            <p class="text-muted">No Ability items.</p>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <button class="submit-skill btn btn-primary mt-3">Save & Next</button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <hr>
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
                                            <div class="cardHeader"
                                                style="display: flex;background:#cff4fc;padding:8px;border-radius:8px;">
                                                <span class="badge bg-primary align-self-start"
                                                    style="margin-right:6px;border-radius:100%">{{ $key + 1 }}</span>
                                                <h5 class="card-title text-primary fw-bold align-self-start"
                                                    style="margin:0px !important"> {{ $item['title'] }}</h5>
                                            </div>
                                            <p style="font-size:10px;margin:4px 0px;border-radius:8px;padding:2px;">
                                                {{ $item['category'] }}
                                                {{ $item['sub_category'] ? '> ' . $item['sub_category'] : '' }}</p>
                                            <div class="cardData p-2">
                                                <p class="card-text mb-2" style="font-size:12px;"><strong>Skill
                                                        Level:</strong>
                                                    <span
                                                        style="background:#d1e7dd;padding:4px;border-radius:8px;white-space: nowrap">{{ $levels['skill_levels'][$item['skill_level']] }}</span>
                                                </p>
                                                <p class="card-text mb-2" style="font-size:12px;"><strong>Interest
                                                        Level:</strong>
                                                    <span
                                                        style="background:#fff3cd;padding:4px;border-radius:8px;white-space: nowrap">{{ $levels['interest_levels'][$item['interest_level']] }}</span>
                                                </p>
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

        // Initialize pie chart
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

        // Skill fade-in/out logic
        $(".submit-skill").click(function() {
            let skillBox = $(this).closest(".skill-box");
            let skillId = skillBox.data("skill-id");
            let skillLevel = skillBox.find(".skill-level").val();
            let interestLevel = skillBox.find(".interest-level").val();
            let userId = "{{ $data['id'] }}";
            
            // Validate all knowledge and ability inputs are filled
            let allFilled = true;
            let missingFields = [];
            
            // Check knowledge inputs
            skillBox.find('.knowledge-input').each(function() {
                if (!$(this).val()) {
                    allFilled = false;
                    missingFields.push('Knowledge: ' + $(this).data('key'));
                }
            });
            
            // Check ability inputs
            skillBox.find('.ability-input').each(function() {
                if (!$(this).val()) {
                    allFilled = false;
                    missingFields.push('Ability: ' + $(this).data('key'));
                }
            });
            
            if (!allFilled) {
                alert('Please rate all knowledge and ability items before proceeding:\n\n' + missingFields.join('\n'));
                return false;
            }

            // Create JSON objects for knowledge and ability
            let knowledgeData = {};
            skillBox.find('.knowledge-input').each(function() {
                knowledgeData[$(this).data('key')] = $(this).val();
            });
            
            let abilityData = {};
            skillBox.find('.ability-input').each(function() {
                abilityData[$(this).data('key')] = $(this).val();
            });

            // If validation passed, proceed with AJAX
            $.ajax({
                url: "{{ route('matrix.save') }}",
                type: "POST",
                data: {
                    _token: "{{ csrf_token() }}",
                    skill_id: skillId,
                    skill_level: skillLevel,
                    interest_level: interestLevel,
                    userId: userId,
                    knowledge: JSON.stringify(knowledgeData),
                    ability: JSON.stringify(abilityData)
                },
                success: function() {
                    completedCount++;
                    updateChart(completedCount, totalSkills);

                    skillBox.fadeOut(500, function() {
                        $(this).next(".skill-box").fadeIn(500);
                    });
                },
                error: function(xhr) {
                    alert('Error saving data: ' + xhr.responseText);
                }
            });
        });

        // Hide all skill boxes except first
        $(".skill-box:not(:first)").hide();
    });

    function AKRating(inputId, liName, starId, liID) {
        // Set hidden input value
        $('#' + inputId).val(starId);

        // Find all stars for this input and reset color
        for (let i = 1; i <= 5; i++) {
            $('.' + liName + 'Star' + liID).removeClass('mdi-star');
            $('.' + liName + 'Star' + liID).addClass('mdi-star-outline');
            $('.' + liName + 'Star' + liID).css('color', '#888');
        }
        
        // Set color for selected stars
        for (let i = 1; i <= starId; i++) {
            $('#' + liName + 'Star' + liID + '_' + i).removeClass('mdi-star-outline');
            $('#' + liName + 'Star' + liID + '_' + i).addClass('mdi-star');
            $('#' + liName + 'Star' + liID + '_' + i).css('color', 'gold');
        }
    }
</script>