# SEED REGISTER — tenant 3 (healthcare), 2026-08-11

Every row created by `docs/phase3/_evidence/seed-healthcare.php`.
**Recorded so the slice is identifiable and removable. Nothing existing was
touched: all 108 pre-existing tenant-3 users already held a job role, so every
person here is new.**

| Table | Rows | IDs |
|---|---:|---|
| `hrms_departments` | 3 | 1930, 1931, 1932 |
| `s_user_jobrole` | 7 | 6508, 6509, 6510, 6511, 6512, 6513, 6514 |
| `competency` | 10 | 21, 22, 23, 24, 25, 26, 27, 28, 29, 30 |
| `competency_kasba_item` | 27 | 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64 |
| `jobrole_competency_map` | 23 | 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39 |
| `tbluser` | 14 | 577, 578, 579, 580, 581, 582, 583, 584, 585, 586, 587, 588, 589, 590 |
| `competency_kasba_rating` | 34 | 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49 |
| `course_competency_map` | 8 | 2, 3, 4, 5, 6, 7, 8, 9 |

**Total rows created: 126**

## Updates to rows created by this same script (not to pre-existing rows)

- `hrms_departments.head_user_id` set on the 3 departments above.
- `tbluser.reporting_manager_id` set on 8 of the new users.

## Logins

| Role | Email | Password |
|---|---|---|
| `administrator` | aarti.deshmukh@healthcare.g2g | `G2GDemo@2026` |
| `hr_manager` | nikhil.rao@healthcare.g2g | `G2GDemo@2026` |
| `hr_executive` | sunita.menon@healthcare.g2g | `G2GDemo@2026` |
| `department_head` | rajesh.iyer@healthcare.g2g | `G2GDemo@2026` |
| `reporting_manager` | farida.khan@healthcare.g2g | `G2GDemo@2026` |
| `employee` | vikram.sethi@healthcare.g2g | `G2GDemo@2026` |
| `employee` | meera.pillai@healthcare.g2g | `G2GDemo@2026` |
| `employee` | joseph.mathew@healthcare.g2g | `G2GDemo@2026` |
| `employee` | anjali.bose@healthcare.g2g | `G2GDemo@2026` |
| `employee` | imran.sheikh@healthcare.g2g | `G2GDemo@2026` |
| `employee` | divya.nair@healthcare.g2g | `G2GDemo@2026` |
| `recruiter` | kabir.chandra@healthcare.g2g | `G2GDemo@2026` |
| `executive` | leela.varma@healthcare.g2g | `G2GDemo@2026` |
| `auditor` | george.thomas@healthcare.g2g | `G2GDemo@2026` |

## Removal

Delete in this order (children first):
`competency_kasba_rating` -> `competency_kasba_item` -> `jobrole_competency_map`
-> `course_competency_map` -> `competency` -> `tbluser` -> `s_user_jobrole`
-> `hrms_departments`, using the ids above.

## G-UI-01 (added 2026-08-11)

| Table | Rows | IDs |
|---|---:|---|
| `tblmenumaster_g2g` | 1 | 224 |
| `tblgroupwise_rights_g2g` | 89 | 22308, 22309, 22310, 22311, 22312, 22313, 22314, 22315, 22316, 22317, 22318, 22319, 22320, 22321, 22322, 22323, 22324, 22325, 22326, 22327, 22328, 22329, 22330, 22331, 22332, 22333, 22334, 22335, 22336, 22337, 22338, 22339, 22340, 22341, 22342, 22343, 22344, 22345, 22346, 22347, 22348, 22349, 22350, 22351, 22352, 22353, 22354, 22355, 22356, 22357, 22358, 22359, 22360, 22361, 22362, 22363, 22364, 22365, 22366, 22367, 22368, 22369, 22370, 22371, 22372, 22373, 22374, 22375, 22376, 22377, 22378, 22379, 22380, 22381, 22382, 22383, 22384, 22385, 22386, 22387, 22388, 22389, 22390, 22391, 22392, 22393, 22394, 22395, 22396 |

**View-only rights.** Removing them removes the route; the component stays.

## 9-box performance axis (added 2026-08-11)

| Table | Rows | IDs |
|---|---:|---|
| `s_performance_reviews` | 6 | 269, 270, 271, 272, 273, 274 |

Added so the 9-box has both axes in ONE tenant. Removing them removes the
demonstration, not the capability.
