# WordPress connect-guide screenshots

Real WordPress admin screenshots used by `resources/views/partials/wp-connect-guide.blade.php`.
Each is optional: a missing file falls back to a drawn SVG of the same screen, so a lost asset
never leaves a step unillustrated.

| File | Screen |
|---|---|
| `01-application-passwords.png` | Profile → Application Passwords, name box + Add button |
| `02-password-revealed.png` | The one-time generated password with the Copy button |
| `03-username.png` | Name → Username (greyed, "Usernames cannot be changed") |

⚠️ **Never commit a screenshot containing a real application password.** These ship to every
customer who opens the integrations page. Redact the value before saving — a black box over the
password is enough.

Annotations live in the blade as HTML captions, not baked into the images, so they stay
translatable and can be reworded without re-exporting.
