<div class="container">
    <!-- Header Section -->
    <div class="text-center mb-5">
        <h2 class="fw-semibold text-dark">Jobrole: 
            {{ $jobroleSkills[0]->jobrole ?? 'Please map jobrole..' }}
        </h2>
    </div>

    <!-- Cards Section -->
    <div class="row g-4">
        @foreach ($jobroleSkills as $index=> $jobrole)
            <div class="col-md-3 col-lg-3" style="margin:12px 0px">
                <div class="card h-100 border-0 rounded-4 custom-card" style="box-shadow:#e8efed 6px 6px 4px 4px;">
                    <div class="card-body d-flex flex-column mb-2">
                        <!-- Task Title -->
                        <div class="card-title mb-3 text-white fw-bold px-3 py-2 rounded"
                            style="background: linear-gradient(45deg, #09AFE8, #29F499);height:75px;font-size:16px;cursor:pointer;"
                            title="{{ $jobrole->skill }}"
                            data-bs-toggle="modal"
                            data-bs-target="#skillModal"
                            onclick="loadSkillData({{ json_encode($jobrole->knowledge) }}, {{ json_encode($jobrole->ability) }}, '{{ $jobrole->skill }}','{{ $jobrole->proficiency_level }}')">
                            {{ substr($jobrole->skill, 0, 100) }} ...
                        </div>
                        <div class="dataDiv px-3 py-2">
                            <!-- Skill Description -->
                            <p class="card-text text-secondary flex-grow-1"><b><u>Skill Description :</u></b><br>{{ $jobrole->description }}</p>
                            <!--<p class="card-text text-secondary flex-grow-1"><b><u>Proficiency Level :</u></b><br>{{$jobrole->proficiency_level}}</p>-->
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<style>
    .custom-card:hover {
        transform: translateY(-5px);
        transition: all 0.3s ease-in-out;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
    }

    .bg-gradient-primary {
        background: linear-gradient(135deg, #4e73df, #1cc88a);
        color: white;
    }
</style>
<!-- Modal -->
<div class="modal fade" id="skillModal" tabindex="-1" aria-labelledby="skillModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="skillModalLabel">Skill Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h6><b>Knowledge:</b></h6>
        <ul id="knowledgeList"></ul>

        <h6 class="mt-3"><b>Ability:</b></h6>
        <ul id="abilityList"></ul>
      </div>
    </div>
  </div>
</div>
<script>
    function loadSkillData(knowledge, ability, skillName, proficiency_level) {
        document.getElementById('skillModalLabel').innerHTML = `
              <span>Skill Name:<strong> ${skillName} </strong></span>
              <span class="badge bg-success ms-2">Level ${proficiency_level}</span>
            `;

        const knowledgeList = document.getElementById('knowledgeList');
        const abilityList = document.getElementById('abilityList');

        knowledgeList.innerHTML = '';
        abilityList.innerHTML = '';

        if (Array.isArray(knowledge)) {
            knowledge.forEach(k => {
                knowledgeList.innerHTML += `<li>★ ${k}</li>`;
            });
        }

        if (Array.isArray(ability)) {
            ability.forEach(a => {
                abilityList.innerHTML += `<li>★ ${a}</li>`;
            });
        }
    }
</script>
