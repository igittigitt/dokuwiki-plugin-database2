<?php
$lang['authSlots'] = 'Provide one authentication slot per line for remote DB authentication here. The syntax is <br /><code>&lt;slotname&gt;=&lt;username&gt;:&lt;password&gt;</code>';
$lang['aliasing'] = 'Enable support for aliasing:<br /><strong style="color:red;">Vulnerability Alert:</strong> A page editor might use this for SQL injections, so do not enable in an open Wiki or if unsure!';
$lang['customviews'] = 'Enable support for custom read-only views:<br /><strong style="color:red;">Vulnerability Alert:</strong> This enables a page\'s editors to send <strong>arbitrary</strong> queries to your database, so do not enable in an open Wiki or if unsure!';
$lang['checkmaildomains'] = 'Enable domain name resolution on validating mail addresses:<br />(might have an impact on validation performance)';
$lang['console'] = 'Enable local DB console:<br /><strong style="color:red;">Vulnerability Alert:</strong> This enables all administrators to send <strong>arbitrary</strong> queries to your local databases!';
$lang['consoleforcehistory'] = 'Force using history in console:<br />(Enabling this might lead to blank screen on invoking console!)';
$lang['enableallpages'] = 'Enable embedding database2 on every Wiki page:<br /><strong style="color:red;">Vulnerability Alert:</strong> On sites running plugin <code>discussion</code> or similar use of database2 should be limited to prevent severe vulnerability!';
$lang['enablepages'] = 'Patterns explicitly selecting enabled pages:<br />Provide one pattern per line. Wrap PCRE in slashes or use shell patterns!<br /><strong style="color:red;">Vulnerability Alert:</strong> On sites running plugin <code>discussion</code> or similar use of database2 should be limited to prevent severe vulnerability!';
$lang['develusers'] = 'Comma separated list of usernames who should use a development-version of database2-library, if available';
