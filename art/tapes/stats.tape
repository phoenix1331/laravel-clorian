# php artisan clorian:stats in test-app/: counts, review rates and bands from the seeded tickets.
Output art/stats.gif
Source art/tapes/settings.tape
# The summary table is wide: a smaller font keeps every column on one line.
Set FontSize 14
Set Height 760

Hide
Type "unset CLAUDECODE AI_AGENT && cd test-app && clear"
Enter
Show

Type `php artisan clorian:stats 'App\Clorian\TicketTriage'`
Sleep 400ms
Enter
Sleep 7s
