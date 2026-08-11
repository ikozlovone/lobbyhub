<x-mail::message>
# New contact form message

**From:** {{ $fromName ? $fromName.' <'.$fromEmail.'>' : $fromEmail }}
**IP:** {{ $ip }}
**Subject:** {{ $subject }}

---

{!! nl2br(e($body)) !!}

---

Reply to this email to reach {{ $fromEmail }} — the reply-to is set for you.

Thanks,<br>
LobbyHub
</x-mail::message>
