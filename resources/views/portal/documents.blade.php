<x-portal.layout title="Documents">
    <x-portal.card title="Upload a document" description="PDF, Word, JPG or PNG, up to {{ intdiv($maxKilobytes, 1024) }} MB. Your recruiter will review it.">
        <form method="POST" action="{{ route('portal.documents.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4 sm:flex-row sm:items-end">
            @csrf
            <label class="flex flex-1 flex-col gap-1 text-sm font-medium">
                Document type
                <select name="document_type" required class="@include('portal.partials.input-class')">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('document_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-1 flex-col gap-1 text-sm font-medium">
                File
                <input type="file" name="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="@include('portal.partials.input-class')">
            </label>
            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Upload</button>
        </form>
    </x-portal.card>

    <x-portal.card title="Documents you have shared">
        @forelse ($documents as $document)
            <div class="flex items-center justify-between gap-3 border-b border-line py-2 text-sm last:border-b-0">
                <span>{{ $document->document_type->label() }}</span>
                <span class="text-ink-muted">{{ $document->status->label() }} · {{ $document->created_at->format('d M Y') }}</span>
            </div>
        @empty
            <p class="text-sm text-ink-muted">You haven't shared any documents yet.</p>
        @endforelse
    </x-portal.card>
</x-portal.layout>
