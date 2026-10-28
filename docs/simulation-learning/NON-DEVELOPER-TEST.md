# Test with three people who are not developers

The point: a customer with no developer can make and publish a new simulation from the sample. Nobody on the build team can judge this for themselves, so three people from outside it do.

**Who.** Three people who have never written code for this product (staff, trainers, a customer contact). One observer sits with each; the observer does not help, they only write things down.

**Before.** The new code is deployed or running on a test copy; the person has a login that may add simulations (an Admin). A computer with a text editor (Notepad is fine) and a way to make a zip (right-click, Send to, Compressed folder).

**What you tell them (read it out, nothing more):**
"Open Simulation Learning, press Add simulation, and choose the Create tab. Download a sample. Make your own simulation from it: give it a new name and change something you care about, such as a control's starting value or the words of a mission. Then put it into Simulation Learning, look at it as a learner would, and publish it. Say out loud what you are thinking."

**The observer records** (a stopwatch and a sheet):
1. The time from "start" to "published and visible in the list". Target: under 30 minutes.
2. Every question the person asks, and every time they stop for more than 30 seconds, with what was on the screen.
3. Every error message that appears and whether the person understood it.
4. Whether any developer help was needed (any help counts as a fail for this check).
5. What they changed, and whether it shows in the published simulation.

**Pass.** All three people publish a changed simulation with no help and no code edit, each in under 30 minutes.

**Whatever happens, send back** the sheets and the downloaded zips; every question and every pause is a place where the screen or the guide (`docs/AUTHORING.md`, `samples/*/README.md`) must say more.

**After the test,** remove the test simulations (soft delete from the gallery menu) so learners do not see them.
