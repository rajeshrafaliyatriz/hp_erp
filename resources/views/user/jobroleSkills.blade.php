<div class="container">
    <!-- Header Section -->
    <div class="text-center mb-5">
        <h2 class="fw-semibold text-dark">
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
                        <div class="card-title mb-3 text-white fw-bold px-3 py-2 rounded" style="background: linear-gradient(45deg, #09AFE8, #29F499);height:75px;font-size:16px;" title="{{$jobrole->skill}}">
                            {{ substr($jobrole->skill,0,100) }} ...
                        </div>
                        <div class="dataDiv px-3 py-2">
                            <!-- Skill Description -->
                            <p class="card-text text-secondary flex-grow-1"><b><u>Skill Description :</u></b><br>{{ $jobrole->description }}</p>
                            <p class="card-text text-secondary flex-grow-1"><b><u>Proficiency Level :</u></b><br>{{$jobrole->proficiency_level}}</p>
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
