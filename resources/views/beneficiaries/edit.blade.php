<x-layouts::app :title="'Edit Beneficiary'">
    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
        <div>
            <a href="{{ route('beneficiaries.show', $beneficiary) }}"
               class="text-sm text-violet-600 dark:text-violet-400">
                &larr; Back to profile
            </a>
            <h1 class="mt-3 text-3xl font-bold">Edit Beneficiary</h1>
            <p class="mt-1 text-sm text-zinc-500">
                Update profile information. Fields marked * are required.
            </p>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Please check the following:</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ route('beneficiaries.update', $beneficiary) }}"
              enctype="multipart/form-data"
              class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @csrf
            @method('PUT')

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Profile photo</h2>

                @if ($beneficiary->photo_path)
                    <img src="{{ route('beneficiaries.photo', $beneficiary) }}"
                         alt="Current beneficiary photo"
                         width="80" height="80"
                         style="width:80px;height:80px;object-fit:cover;border-radius:50%;">
                @else
                    <p class="text-sm text-zinc-500">No photo uploaded yet.</p>
                @endif

                <label for="photo" class="block text-sm font-medium">
                    Upload a replacement photo
                </label>
                <input id="photo" name="photo" type="file"
                       accept="image/jpeg,image/png,image/webp"
                       aria-describedby="photo-help"
                       class="block w-full rounded-xl border border-zinc-300 p-3 text-sm dark:border-zinc-600">
                <p id="photo-help" class="text-xs text-zinc-500">
                    Leave empty to keep the current photo.
                    JPG, PNG, or WebP, maximum 10 MB.
                    Select the file again if validation fails.
                </p>
            </section>

            @php
                $fields = [
                    'beneficiary_id' => ['Beneficiary ID', 'text', 50, true],
                    'full_name' => ['Full name', 'text', 255, true],
                    'registered_at' => ['Registration date', 'date', null, true],
                    'birthdate' => ['Birthdate', 'date', null, false],
                    'guardian_name' => ['Parent / guardian name', 'text', 255, false],
                    'guardian_contact' => ['Guardian contact number', 'tel', 30, false],
                    'school' => ['School', 'text', 255, false],
                    'grade_level' => ['Grade level', 'text', 50, false],
                ];
            @endphp

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($fields as $name => $field)
                    @php
                        $savedValue = $field[1] === 'date'
                            ? $beneficiary->{$name}?->format('Y-m-d')
                            : $beneficiary->{$name};
                    @endphp

                    <div>
                        <label for="{{ $name }}" class="mb-2 block text-sm font-medium">
                            {{ $field[0] }}{{ $field[3] ? ' *' : '' }}
                        </label>
                        <input id="{{ $name }}" name="{{ $name }}"
                               type="{{ $field[1] }}"
                               value="{{ old($name, $savedValue) }}"
                               @required($field[3])
                               @if ($field[2]) maxlength="{{ $field[2] }}" @endif
                               @if ($name === 'birthdate') max="{{ now()->toDateString() }}" @endif
                               class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                    </div>
                @endforeach

                <div class="md:col-span-2">
                    <label for="address" class="mb-2 block text-sm font-medium">
                        Address
                    </label>
                    <textarea id="address" name="address" rows="3" maxlength="1000"
                              class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">{{ old('address', $beneficiary->address) }}</textarea>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <button type="submit"
                        class="rounded-xl bg-violet-600 px-6 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                    Save Changes
                </button>
                <a href="{{ route('beneficiaries.show', $beneficiary) }}"
                   class="rounded-xl border border-zinc-300 px-6 py-3 text-sm dark:border-zinc-600">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-layouts::app>