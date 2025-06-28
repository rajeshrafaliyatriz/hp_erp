<div class="container">
    <!-- Header Section -->
    <div class="text-center mb-5">
        <h2 class="fw-semibold text-dark">Jobrole:
            {{ $jobroleTasks[0]->jobrole ?? 'Please map jobrole..' }}
        </h2>
    </div>

    <!-- Cards Section -->
    <div class="row g-4">
        @foreach ($jobroleTasks as $index=> $jobrole)
            <div class="col-md-12 col-lg-12" style="margin:12px 0px">
                <div class="card border-0 rounded-4 custom-card" style="box-shadow:#e8efed 6px 6px 4px 4px;">
                    <div class="card-body d-flex flex-column mb-2">
                        <div class="card-title mb-3 text-white fw-bold px-3 py-2 rounded" style="background: linear-gradient(45deg, #09AFE8, #29F499);font-size:16px;" title="{{$jobrole->task}}">
                            {{ substr($jobrole->task,0,191) }}
                        </div>
                        <div class="dataDiv px-3 py-2">
                            <p class="card-text text-secondary flex-grow-1"><b><u>Critical Work Function :</u></b>{{ $jobrole->critical_work_function }}</p>    
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
