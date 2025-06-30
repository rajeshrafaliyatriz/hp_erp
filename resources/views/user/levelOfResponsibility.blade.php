<style>
    .levelHead {
        background-color: #bbb1f1;
        padding: 20px 15px;
        border-radius: 10px 10px 0px 0px;
        font-size: 30px;
        font-weight: bold;
    }

    .detailsDiv {
        background-color: #e7efff;
        padding: 6px 15px;
    }

    .detailsDiv p,
    .detailsDiv h2,
    .detailsDiv h3,
    .detailsDiv h4 {
        margin: 0px !important;
    }

    .guidenceNotes {
        background-color: #f3f3f3;
        border-radius: 10px;
        padding: 10px;
    }

    .guidenceNotes {
        background-color: #f3f3f3;
        border-radius: 10px;
        padding: 10px;
    }

    .guidenceNotes h3 {
        font-weight: bold;
    }

    .bussinessSkill {
        background-color: #dedfff;
        border-radius: 10px;
        padding: 16px 4px;
    }
</style>
@if(isset($usersLevelData['levelsData'][0]))
<div class="levelDiv">
    <div class="levelHead">Level of Responsibility:
        {{ isset($usersLevelData['levelsData'][0]) && !empty($usersLevelData['levelsData'][0]) ? 'Level ' . $usersLevelData['levelsData'][0]['level'] . '-' . $usersLevelData['levelsData'][0]['guiding_phrase'] : '' }}
    </div>
    <div class="detailsDiv">

        <p>{{ isset($usersLevelData['levelsData'][0]) && !empty($usersLevelData['levelsData'][0]) ? $usersLevelData['levelsData'][0]['essence_level'] : '' }}
        </p>

        <div class="guidenceNotes">
            <h3>Guidance notes</h3>
            <p>{{ isset($usersLevelData['levelsData'][0]) && !empty($usersLevelData['levelsData'][0]) ? $usersLevelData['levelsData'][0]['guidance_notes'] : '' }}
            </p>
        </div>
        @php
            $attributes = isset($usersLevelData['attrData'][$data['subject_ids']]['Attributes'])
                ? $usersLevelData['attrData'][$data['subject_ids']]['Attributes']
                : [];
            $businss_skills = isset($usersLevelData['attrData'][$data['subject_ids']]['Business_skills'])
                ? $usersLevelData['attrData'][$data['subject_ids']]['Business_skills']
                : [];
        @endphp
        @if (isset($usersLevelData['levelsData'][0]) && !empty($attributes))
            @foreach ($attributes as $key => $val)
                <div style="padding:6px 0px;">
                    <h4 style="background:#a5cef7;padding:6px;width:fit-content;border-radius:10px">
                        <b>{{ $key }}</b></h4>
                    <p style="padding:4px 0px;">{{ $val['attribute_description'] }}</p>
                </div>
            @endforeach
        @endif

        @if (isset($usersLevelData['levelsData'][0]) && !empty($businss_skills))
            <div class="bussinessSkill">
                <h3><b>Business skills / Behavioural factors</b></h3>
                @foreach ($businss_skills as $key => $val)
                    <div style="padding:6px 0px;">
                        <h4 style="background:#ceb0fddd;padding:6px;width:fit-content;border-radius:10px">
                            <b>{{ $key }}</b></h4>
                        <p style="padding:4px 0px;">{{ $val['attribute_description'] }}</p>
                    </div>
                @endforeach
        @endif
    </div>
</div>

</div>
@else 
<h2 style="text-align:center">Please Map Level of Responsibility..</h2>
@endif 