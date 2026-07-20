<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Danke für Ihre Registrierung! Bevor es losgeht: Bitte bestätigen Sie Ihre E-Mail-Adresse über den Link, den wir Ihnen soeben per E-Mail gesendet haben. Falls Sie die E-Mail nicht erhalten haben, senden wir Ihnen gerne eine neue.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ __('Ein neuer Bestätigungslink wurde an die bei der Registrierung angegebene E-Mail-Adresse gesendet.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ __('Bestätigungs-E-Mail erneut senden') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{ __('Abmelden') }}
            </button>
        </form>
    </div>
</x-guest-layout>
