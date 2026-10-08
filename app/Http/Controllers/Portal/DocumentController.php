<?php

namespace App\Http\Controllers\Portal;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UploadDocumentRequest;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Candidates can upload documents and see what they have shared, but never download stored files
 * back through the portal — files stay on the private disk for recruiters.
 */
class DocumentController extends Controller
{
    public function index(Request $request, CandidatePortalService $portal): View
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');

        return view('portal.documents', [
            'documents' => $portal->documentsFor($account),
            'types' => CandidatePortalService::UPLOADABLE_DOCUMENT_TYPES,
            'maxKilobytes' => UploadDocumentRequest::MAX_KILOBYTES,
        ]);
    }

    public function store(UploadDocumentRequest $request, CandidatePortalService $portal): RedirectResponse
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');

        $portal->uploadDocument($account, $request->file('file'), DocumentType::from($request->string('document_type')->toString()));

        return back()->with('status', 'Thanks — your document was uploaded and will be reviewed by your recruiter.');
    }
}
