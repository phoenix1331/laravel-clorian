# php artisan clorian:tune in test-app/: before and after, a confirm, then saved.
Output docs/images/tune.gif
Source art/tapes/settings.tape
Set Height 560

Hide
# Start from no tuned row for urgency / low, so every run records the same thing.
Type `unset CLAUDECODE AI_AGENT && cd test-app && php artisan tinker --execute 'Phoenix1331\Clorian\Models\TunedThreshold::where("question", "urgency")->where("option", "low")->delete();' > /dev/null && clear`
Enter
Sleep 3s
Show

Type `php artisan clorian:tune 'App\Clorian\TicketTriage' urgency --option=low --accept=0.85`
Sleep 400ms
Enter
Sleep 2.5s
# Save these thresholds? Move to Yes, then confirm.
Left
Sleep 600ms
Enter
Sleep 4s
