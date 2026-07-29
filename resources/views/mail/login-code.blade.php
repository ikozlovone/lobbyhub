<x-mail::message>
# Your sign-in code

Enter this code to finish signing in to LobbyHub:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

It works for {{ $minutes }} minutes and only once.

If you did not ask to sign in, nothing has happened to your account — you can ignore this message.

Thanks,<br>
LobbyHub
</x-mail::message>
