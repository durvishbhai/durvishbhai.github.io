<?php

declare(strict_types=1);

use Vertex\Core\Router;
use Vertex\Http\Controllers\AccountController;
use Vertex\Http\Controllers\Admin\AuditController;
use Vertex\Http\Controllers\Admin\RevisionTermsController;
use Vertex\Http\Controllers\Admin\AutomationController;
use Vertex\Http\Controllers\Admin\DataController;
use Vertex\Http\Controllers\Admin\RecycleBinController;
use Vertex\Http\Controllers\Admin\RoleController;
use Vertex\Http\Controllers\SearchController;
use Vertex\Http\Controllers\Admin\UserController;
use Vertex\Http\Controllers\Admin\CrmStageController;
use Vertex\Http\Controllers\AttachmentController;
use Vertex\Http\Controllers\AuthController;
use Vertex\Http\Controllers\ClientController;
use Vertex\Http\Controllers\Crm\DashboardController as CrmDashboardController;
use Vertex\Http\Controllers\Crm\EnquiryController;
use Vertex\Http\Controllers\Crm\PartnerReferralController;
use Vertex\Http\Controllers\PublicFormController;
use Vertex\Http\Controllers\DashboardController;
use Vertex\Http\Controllers\HealthController;
use Vertex\Http\Controllers\HomeController;
use Vertex\Http\Controllers\MilestoneController;
use Vertex\Http\Controllers\PasswordController;
use Vertex\Http\Controllers\PricingController;
use Vertex\Http\Controllers\ProjectController;
use Vertex\Http\Controllers\TaskController;
use Vertex\Http\Controllers\NotificationController;
use Vertex\Http\Controllers\ChangeRequestController;
use Vertex\Http\Controllers\RevisionController;
use Vertex\Http\Controllers\DeliverableController;
use Vertex\Http\Controllers\TeamController;
use Vertex\Http\Controllers\SupportController;
use Vertex\Http\Controllers\PartnerApplyController;
use Vertex\Http\Controllers\Partner\PortalController as PartnerPortalController;
use Vertex\Http\Controllers\Referrals\ClientPaymentController;
use Vertex\Http\Controllers\Referrals\CommissionController;
use Vertex\Http\Controllers\Referrals\PayoutController;
use Vertex\Http\Controllers\Referrals\ReferralAdminController;
use Vertex\Http\Controllers\TicketController;
use Vertex\Http\Controllers\WarrantyController;
use Vertex\Http\Controllers\RegistrationController;
use Vertex\Http\Controllers\Admin\BackupController;
use Vertex\Http\Controllers\Admin\BillingSettingsController;
use Vertex\Http\Controllers\Admin\SystemController;
use Vertex\Http\Controllers\Admin\CmsController;
use Vertex\Http\Controllers\Admin\SettingsController;
use Vertex\Http\Controllers\AiController;
use Vertex\Http\Controllers\PublicPageController;
use Vertex\Http\Controllers\SitePageController;
use Vertex\Http\Controllers\EnquiryFileController;
use Vertex\Http\Controllers\Admin\SiteAdminController;
use Vertex\Http\Controllers\Admin\CareerAdminController;
use Vertex\Http\Controllers\Billing\PaymentRequestController;
use Vertex\Http\Controllers\Billing\RazorpayController;
use Vertex\Http\Controllers\Billing\CouponController;
use Vertex\Http\Controllers\Billing\DocumentController;
use Vertex\Http\Controllers\Billing\InvoiceController;
use Vertex\Http\Controllers\AgreementController;
use Vertex\Http\Controllers\ApprovalController;
use Vertex\Http\Controllers\FeedbackController;
use Vertex\Http\Controllers\ProjectKitController;
use Vertex\Http\Controllers\ScopeRequestController;
use Vertex\Http\Controllers\DynamicFormController;
use Vertex\Http\Controllers\FormController;
use Vertex\Http\Controllers\ReportController;
use Vertex\Http\Controllers\Billing\MyBillingController;
use Vertex\Http\Controllers\Billing\QuotationController;
use Vertex\Http\Controllers\Renewals\ClientServiceController;
use Vertex\Http\Controllers\Renewals\RenewalController;
use Vertex\Http\Controllers\Renewals\RenewalPriceController;

/*
 * Route table. Every route is backed by working code. Middleware aliases:
 *   auth            signed-in, active account required
 *   guest           redirect signed-in users away
 *   can:a[,b]       needs ANY of the listed permissions (coarse gate; services re-check + apply row/hierarchy rules)
 *   project         resolves {project}, runs ProjectPolicy, 404 if missing or not accessible
 *   nosession       don't start a session for anonymous visitors
 * CSRF is enforced globally for every non-GET request.
 */
return static function (Router $router): void {
    // Public
    $router->get('/', [HomeController::class, 'index'], ['nosession']);
    $router->get('/health', [HealthController::class, 'show'], ['nosession']);
    // The corporate website (all content is editable in Administration -> Website). Pages with a form start a session for the CSRF token; the rest do not.
    $router->get('/about', [SitePageController::class, 'about'], ['nosession']);
    $router->get('/services', [SitePageController::class, 'services'], ['nosession']);
    $router->get('/services/{slug}', [SitePageController::class, 'service'], ['nosession']);
    $router->get('/our-team', [SitePageController::class, 'team'], ['nosession']);
    $router->get('/our-clients', [SitePageController::class, 'clients'], ['nosession']);
    $router->get('/our-partners', [SitePageController::class, 'partners'], ['nosession']);
    $router->get('/portfolio', [SitePageController::class, 'portfolio'], ['nosession']);
    $router->get('/portfolio/{slug}', [SitePageController::class, 'project'], ['nosession']);
    $router->get('/hiring', [SitePageController::class, 'hiring']);
    $router->get('/hiring/received', [SitePageController::class, 'hiringReceived']);
    $router->post('/hiring/apply', [SitePageController::class, 'hiringApply']);
    $router->get('/careers', [SitePageController::class, 'careers']);
    $router->get('/careers/received', [SitePageController::class, 'applied']);
    $router->get('/careers/{slug}', [SitePageController::class, 'job'], ['nosession']);
    $router->post('/careers/apply', [SitePageController::class, 'apply']);
    $router->get('/faq', [SitePageController::class, 'faq'], ['nosession']);
    $router->get('/sitemap', [SitePageController::class, 'sitemap'], ['nosession']);
    $router->get('/project-requirements', [SitePageController::class, 'requirementsAlias'], ['nosession']);
    foreach (['privacy-policy', 'terms-and-conditions', 'refund-and-cancellation-policy', 'payment-policy', 'cookie-policy'] as $policy) {
        $router->get('/' . $policy, [SitePageController::class, 'policy'], ['nosession']);
    }
    $router->get('/sitemap.xml', [PublicPageController::class, 'sitemap'], ['nosession']);
    $router->get('/robots.txt', [PublicPageController::class, 'robots'], ['nosession']);
    // Razorpay calls this with its own signature header: no session, no CSRF token. The handler rejects anything without a valid X-Razorpay-Signature.
    $router->post('/webhooks/razorpay', [RazorpayController::class, 'webhook'], ['nosession'], false);

    // Authentication
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest']);
    $router->get('/login/two-factor', [AuthController::class, 'showTwoFactor'], ['guest']);
    $router->post('/login/two-factor', [AuthController::class, 'twoFactor'], ['guest']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);

    // Registration & email verification (404 unless enabled and email configured)
    $router->get('/register', [RegistrationController::class, 'showRegister'], ['guest']);
    $router->post('/register', [RegistrationController::class, 'register'], ['guest']);
    $router->get('/verify-email', [RegistrationController::class, 'showResend']);
    $router->post('/verify-email', [RegistrationController::class, 'resend']);
    $router->get('/verify-email/{token}', [RegistrationController::class, 'showVerify']);
    $router->post('/verify-email/{token}', [RegistrationController::class, 'verify']);

    // Password reset & invitations
    $router->get('/forgot-password', [PasswordController::class, 'showForgot'], ['guest']);
    $router->post('/forgot-password', [PasswordController::class, 'forgot'], ['guest']);
    $router->get('/reset-password/{token}', [PasswordController::class, 'showReset']);
    $router->post('/reset-password/{token}', [PasswordController::class, 'submitReset']);
    $router->get('/invite/{token}', [PasswordController::class, 'showInvite']);
    $router->post('/invite/{token}', [PasswordController::class, 'submitInvite']);

    // Signed-in self-service
    $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
    $router->get('/search', [SearchController::class, 'index'], ['auth']);
    $router->get('/account', [AccountController::class, 'show'], ['auth']);
    $router->post('/account', [AccountController::class, 'update'], ['auth']);
    $router->post('/account/password', [AccountController::class, 'password'], ['auth']);
    $router->get('/account/two-factor', [AccountController::class, 'twoFactor'], ['auth']);
    $router->post('/account/two-factor/start', [AccountController::class, 'twoFactorStart'], ['auth']);
    $router->post('/account/two-factor/enable', [AccountController::class, 'twoFactorEnable'], ['auth']);
    $router->post('/account/two-factor/disable', [AccountController::class, 'twoFactorDisable'], ['auth']);
    $router->post('/account/two-factor/recovery', [AccountController::class, 'twoFactorRecovery'], ['auth']);

    // Projects. `project` middleware: {project} must be visible to the user (404 otherwise). `can:` is the first gate only;
    // services re-check permission + staff-level access on every mutation.
    $p = ['auth', 'project'];
    $router->get('/projects', [ProjectController::class, 'index'], ['auth', 'can:projects.view_all,projects.view_assigned,projects.view_own']);
    $router->get('/projects/create', [ProjectController::class, 'create'], ['auth', 'can:projects.create']);
    $router->post('/projects', [ProjectController::class, 'store'], ['auth', 'can:projects.create']);
    $router->get('/projects/{project}', [ProjectController::class, 'show'], $p);
    $router->post('/projects/{project}', [ProjectController::class, 'update'], ['auth', 'can:projects.edit', 'project']);
    $router->post('/projects/{project}/status', [ProjectController::class, 'transition'], ['auth', 'can:projects.update_status,projects.complete,projects.edit', 'project']);
    $router->post('/projects/{project}/notes', [ProjectController::class, 'note'], ['auth', 'can:projects.notes_internal,projects.notes_client,projects.give_feedback', 'project']);
    $router->post('/projects/{project}/archive', [ProjectController::class, 'archive'], ['auth', 'can:projects.archive', 'project']);
    $router->get('/projects/{project}/timeline', [ProjectController::class, 'timeline'], $p);
    $router->post('/projects/{project}/assignments', [ProjectController::class, 'assign'], ['auth', 'can:projects.assign', 'project']);
    $router->post('/projects/{project}/assignments/reassign', [ProjectController::class, 'reassign'], ['auth', 'can:projects.assign', 'project']);
    $router->post('/projects/{project}/assignments/{user}/revoke', [ProjectController::class, 'revoke'], ['auth', 'can:projects.assign', 'project']);
    $router->get('/projects/{project}/milestones', [MilestoneController::class, 'index'], $p);
    $router->post('/projects/{project}/milestones', [MilestoneController::class, 'store'], ['auth', 'can:milestones.create,milestones.propose', 'project']);
    $router->post('/projects/{project}/milestones/{milestone}', [MilestoneController::class, 'update'], ['auth', 'can:milestones.edit', 'project']);
    $router->post('/projects/{project}/milestones/{milestone}/status', [MilestoneController::class, 'status'], ['auth', 'can:milestones.edit,milestones.approve', 'project']);
    $router->post('/projects/{project}/milestones/{milestone}/archive', [MilestoneController::class, 'archive'], ['auth', 'can:milestones.edit', 'project']);

    // Priority pricing: project price history and priority-change proposals. The client decides; staff only propose.
    $router->get('/projects/{project}/pricing', [PricingController::class, 'project'], $p);
    $router->post('/projects/{project}/priority', [PricingController::class, 'priority'], ['auth', 'can:projects.set_priority', 'project']);
    $router->post('/projects/{project}/pricing/baseline', [PricingController::class, 'baseline'], ['auth', 'can:projects.record_approval', 'project']);
    $router->post('/projects/{project}/pricing/requests/{req}/decision', [PricingController::class, 'decide'], ['auth', 'can:pricing.respond_change', 'project']);
    $router->post('/projects/{project}/pricing/requests/{req}/withdraw', [PricingController::class, 'withdraw'], ['auth', 'can:pricing.request_change', 'project']);

    // Deliverables, versions, QA, client approvals, revisions, change requests, notifications (Phase 3).
    // `can:` is the first gate only; DeliverablePolicy / RevisionAccess decide per record (404) and the services re-check every permission.
    $dv = ['auth', 'can:deliverables.view'];
    $router->get('/deliverables', [DeliverableController::class, 'index'], $dv);
    $router->get('/qa', [DeliverableController::class, 'qa'], ['auth', 'can:deliverables.review,deliverables.publish']);
    $router->get('/my/deliverables', [DeliverableController::class, 'clientDashboard'], ['auth', 'can:deliverables.view,projects.view_own']);
    $router->get('/projects/{project}/deliverables', [DeliverableController::class, 'projectIndex'], ['auth', 'can:deliverables.view', 'project']);
    $router->post('/projects/{project}/deliverables', [DeliverableController::class, 'store'], ['auth', 'can:deliverables.upload', 'project']);
    $router->get('/deliverables/{deliverable}', [DeliverableController::class, 'show'], $dv);
    $router->post('/deliverables/{deliverable}', [DeliverableController::class, 'update'], ['auth', 'can:deliverables.upload']);
    $router->post('/deliverables/{deliverable}/versions', [DeliverableController::class, 'addVersion'], ['auth', 'can:deliverables.upload']);
    $router->get('/deliverables/{deliverable}/compare', [DeliverableController::class, 'compare'], $dv);
    $router->get('/deliverables/{deliverable}/versions/{version}/download', [DeliverableController::class, 'download'], ['auth', 'can:deliverables.view,files.download']);
    $router->post('/deliverables/{deliverable}/versions/{version}/submit', [DeliverableController::class, 'submit'], ['auth', 'can:deliverables.upload']);
    $router->post('/deliverables/{deliverable}/versions/{version}/review', [DeliverableController::class, 'review'], ['auth', 'can:deliverables.review']);
    $router->post('/deliverables/{deliverable}/versions/{version}/publish', [DeliverableController::class, 'publish'], ['auth', 'can:deliverables.publish']);
    $router->post('/deliverables/{deliverable}/versions/{version}/withdraw', [DeliverableController::class, 'withdraw'], ['auth', 'can:deliverables.publish']);
    $router->post('/deliverables/{deliverable}/archive', [DeliverableController::class, 'archive'], ['auth', 'can:deliverables.archive']);
    $router->get('/deliverables/{deliverable}/respond', [DeliverableController::class, 'respondForm'], ['auth', 'can:deliverables.respond']);
    $router->post('/deliverables/{deliverable}/respond', [DeliverableController::class, 'respond'], ['auth', 'can:deliverables.respond']);
    $router->get('/deliverables/{deliverable}/revisions/new', [RevisionController::class, 'create'], ['auth', 'can:revisions.request']);
    $router->post('/deliverables/{deliverable}/revisions', [RevisionController::class, 'store'], ['auth', 'can:revisions.request']);

    $router->get('/revisions', [RevisionController::class, 'index'], ['auth', 'can:revisions.manage,revisions.request']);
    $router->get('/revisions/{revision}', [RevisionController::class, 'show'], ['auth', 'can:revisions.manage,revisions.request']);
    $router->post('/revisions/{revision}/status', [RevisionController::class, 'transition'], ['auth', 'can:revisions.manage,revisions.request']);
    $router->post('/revisions/{revision}/classify', [RevisionController::class, 'classify'], ['auth', 'can:revisions.classify']);
    $router->post('/revisions/{revision}/override', [RevisionController::class, 'override'], ['auth', 'can:revisions.override']);
    $router->post('/revisions/{revision}/assign', [RevisionController::class, 'assign'], ['auth', 'can:revisions.manage']);
    $router->post('/revisions/{revision}/notes', [RevisionController::class, 'note'], ['auth', 'can:revisions.manage']);
    $router->post('/revisions/{revision}/response', [RevisionController::class, 'response'], ['auth', 'can:revisions.manage']);
    $router->get('/revisions/{revision}/files/{file}', [RevisionController::class, 'file'], ['auth', 'can:revisions.manage,revisions.request']);
    // Referral Partner portal, commissions, client payments (verified-payment source) and payouts (Phase 6).
    // `can:` is the first gate only; the services re-check every permission and derive the partner from the signed-in user.
    $router->get('/partners/apply', [PartnerApplyController::class, 'show']);
    $router->post('/partners/apply', [PartnerApplyController::class, 'submit']);
    $router->get('/partners/apply/received', [PartnerApplyController::class, 'received']);
    $router->get('/partner/application', [PartnerPortalController::class, 'application'], ['auth']);
    $router->get('/partner', [PartnerPortalController::class, 'dashboard'], ['auth', 'can:referrals.view_own']);
    $router->get('/partner/submit', [PartnerPortalController::class, 'submitForm'], ['auth', 'can:referrals.submit']);
    $router->post('/partner/submit', [PartnerPortalController::class, 'submit'], ['auth', 'can:referrals.submit']);
    $router->get('/partner/r/{ref}', [PartnerPortalController::class, 'referral'], ['auth', 'can:referrals.view_own']);
    $router->get('/partner/commissions', [PartnerPortalController::class, 'commissions'], ['auth', 'can:commissions.view_own']);
    $router->get('/partner/payouts', [PartnerPortalController::class, 'payouts'], ['auth', 'can:payouts.view_own']);
    $router->get('/partner/payouts/new', [PartnerPortalController::class, 'payoutForm'], ['auth', 'can:payouts.request']);
    $router->post('/partner/payouts', [PartnerPortalController::class, 'payoutRequest'], ['auth', 'can:payouts.request']);
    $router->get('/partner/payouts/{payout}', [PartnerPortalController::class, 'payout'], ['auth', 'can:payouts.view_own']);
    $router->post('/partner/payouts/{payout}/cancel', [PartnerPortalController::class, 'payoutCancel'], ['auth', 'can:payouts.request']);
    $router->get('/partner/payout-details', [PartnerPortalController::class, 'detailsForm'], ['auth', 'can:payouts.request']);
    $router->post('/partner/payout-details', [PartnerPortalController::class, 'detailsSave'], ['auth', 'can:payouts.request']);
    $rv = ['auth', 'can:referrals.review,commissions.view_all,payouts.view_all,payments.view_all,payments.record,payments.reconcile'];
    $router->get('/referrals/dashboard', [ReferralAdminController::class, 'dashboard'], $rv);
    $router->get('/referrals/applications', [ReferralAdminController::class, 'applications'], ['auth', 'can:referrals.review']);
    $router->get('/referrals/applications/{app}', [ReferralAdminController::class, 'application'], ['auth', 'can:referrals.review']);
    $router->post('/referrals/applications/{app}', [ReferralAdminController::class, 'applicationAction'], ['auth', 'can:referrals.review']);
    $router->get('/referrals/applications/{app}/files/{file}', [ReferralAdminController::class, 'applicationFile'], ['auth', 'can:referrals.review']);
    $router->get('/referrals/terms', [ReferralAdminController::class, 'terms'], ['auth', 'can:referrals.review']);
    $router->post('/referrals/terms', [ReferralAdminController::class, 'saveTerms'], ['auth', 'can:referrals.decide']);
    $router->get('/referrals/partners', [ReferralAdminController::class, 'partners'], ['auth', 'can:referrals.review,commissions.view_all,payouts.view_all']);
    $router->get('/referrals/partners/{partner}', [ReferralAdminController::class, 'partner'], ['auth', 'can:referrals.review,commissions.view_all,payouts.view_all']);
    $router->post('/referrals/partners/{partner}', [ReferralAdminController::class, 'partnerAction'], ['auth', 'can:referrals.decide']);
    $router->get('/referrals', [ReferralAdminController::class, 'index'], ['auth', 'can:referrals.review']);
    $router->get('/referrals/{ref}', [ReferralAdminController::class, 'show'], ['auth', 'can:referrals.review']);
    $router->post('/referrals/{ref}', [ReferralAdminController::class, 'action'], ['auth', 'can:referrals.review']);
    $router->get('/referrals/{ref}/email/{history}', [ReferralAdminController::class, 'emailPreview'], ['auth', 'can:referrals.manage']);
    $router->post('/referrals/{ref}/email/{history}', [ReferralAdminController::class, 'emailSend'], ['auth', 'can:referrals.manage']);
    $router->get('/commissions', [CommissionController::class, 'index'], ['auth', 'can:commissions.view_all']);
    $router->get('/commissions/rules', [CommissionController::class, 'rules'], ['auth', 'can:commissions.view_all,commissions.rules.manage']);
    $router->post('/commissions/rules', [CommissionController::class, 'saveRules'], ['auth', 'can:commissions.rules.manage']);
    $router->get('/commissions/{entry}', [CommissionController::class, 'show'], ['auth', 'can:commissions.view_all']);
    $router->post('/commissions/{entry}', [CommissionController::class, 'action'], ['auth', 'can:commissions.view_all']);
    $cp = ['auth', 'can:payments.view_all,payments.record,payments.reconcile'];
    $router->get('/client-payments', [ClientPaymentController::class, 'index'], $cp);
    $router->get('/client-payments/new', [ClientPaymentController::class, 'create'], ['auth', 'can:payments.record']);
    $router->post('/client-payments', [ClientPaymentController::class, 'store'], ['auth', 'can:payments.record']);
    $router->get('/client-payments/{pay}', [ClientPaymentController::class, 'show'], $cp);
    $router->post('/client-payments/{pay}/reject', [ClientPaymentController::class, 'reject'], $cp);
    $router->post('/client-payments/{pay}/verify', [ClientPaymentController::class, 'verify'], ['auth', 'can:payments.reconcile']);
    $router->post('/client-payments/{pay}/refund', [ClientPaymentController::class, 'refund'], ['auth', 'can:payments.reconcile']);
    $po = ['auth', 'can:payouts.view_all,payouts.approve,payouts.record'];
    $router->get('/payouts', [PayoutController::class, 'index'], $po);
    $router->get('/payouts/{payout}', [PayoutController::class, 'show'], $po);
    $router->post('/payouts/{payout}', [PayoutController::class, 'action'], $po);

    // Domain / hosting / SSL renewals (Phase 7). Static paths first so `/renewals/new` is never read as an id.
    $rn = ['auth', 'can:renewals.view,renewals.manage,renewals.approve,renewals.confirm_provider'];
    $router->get('/renewals', [RenewalController::class, 'index'], $rn);
    $router->get('/renewals/new', [RenewalController::class, 'create'], ['auth', 'can:renewals.manage']);
    $router->post('/renewals', [RenewalController::class, 'store'], ['auth', 'can:renewals.manage']);
    $router->get('/renewals/export', [RenewalController::class, 'export'], ['auth', 'can:renewals.manage']);
    $router->get('/renewals/prices', [RenewalPriceController::class, 'index'], $rn);
    $router->post('/renewals/prices', [RenewalPriceController::class, 'store'], ['auth', 'can:renewals.prices']);
    $router->get('/renewals/{service}', [RenewalController::class, 'show'], $rn);
    $router->post('/renewals/{service}', [RenewalController::class, 'action'], $rn);
    // Quotations, invoices (no tax) and the client's own billing pages (Phase 8). Static paths before `{id}` routes.
    $qs = ['auth', 'can:quotes.create,quotes.send,quotes.record_decision'];
    $router->get('/quotations', [QuotationController::class, 'index'], $qs);
    $router->get('/quotations/new', [QuotationController::class, 'create'], ['auth', 'can:quotes.create']);
    $router->post('/quotations', [QuotationController::class, 'store'], ['auth', 'can:quotes.create']);
    $router->get('/quotations/{quote}', [QuotationController::class, 'show'], $qs);
    $router->post('/quotations/{quote}', [QuotationController::class, 'action'], $qs);
    $iv = ['auth', 'can:invoices.view_all'];
    $router->get('/invoices', [InvoiceController::class, 'index'], $iv);
    $router->get('/invoices/new', [InvoiceController::class, 'create'], ['auth', 'can:invoices.create']);
    $router->post('/invoices', [InvoiceController::class, 'store'], ['auth', 'can:invoices.create']);
    $router->get('/invoices/{invoice}', [InvoiceController::class, 'show'], $iv);
    $router->get('/invoices/{invoice}/print', [InvoiceController::class, 'print'], $iv);
    $router->post('/invoices/{invoice}', [InvoiceController::class, 'action'], $iv);
    $router->get('/invoices/{invoice}/pdf', [DocumentController::class, 'invoicePdf'], $iv);
    $router->get('/quotations/{quote}/pdf', [DocumentController::class, 'quotationPdf'], $qs);
    $router->get('/client-payments/{payment}/receipt', [DocumentController::class, 'receipt'], $cp);
    $router->get('/client-payments/{payment}/receipt/pdf', [DocumentController::class, 'receiptPdf'], $cp);
    $router->get('/credit-notes/{note}', [DocumentController::class, 'creditNote'], $iv);
    $router->get('/credit-notes/{note}/pdf', [DocumentController::class, 'creditNotePdf'], $iv);
    $router->get('/my/invoices/{invoice}/pdf', [DocumentController::class, 'myInvoicePdf'], ['auth', 'can:invoices.view_own']);
    $router->get('/my/quotes/{quote}/pdf', [DocumentController::class, 'myQuotePdf'], ['auth', 'can:quotes.view']);
    $router->get('/my/receipts/{payment}', [DocumentController::class, 'myReceipt'], ['auth', 'can:receipts.view_own']);
    $router->get('/my/receipts/{payment}/pdf', [DocumentController::class, 'myReceiptPdf'], ['auth', 'can:receipts.view_own']);
    $router->get('/my/credit-notes/{note}', [DocumentController::class, 'myCreditNote'], ['auth', 'can:invoices.view_own']);
    $router->get('/my/credit-notes/{note}/pdf', [DocumentController::class, 'myCreditNotePdf'], ['auth', 'can:invoices.view_own']);
    $router->get('/coupons', [CouponController::class, 'index'], ['auth', 'can:coupons.view']);
    $router->get('/coupons/new', [CouponController::class, 'create'], ['auth', 'can:coupons.manage']);
    $router->post('/coupons', [CouponController::class, 'store'], ['auth', 'can:coupons.manage']);
    $router->get('/coupons/{coupon}', [CouponController::class, 'show'], ['auth', 'can:coupons.view']);
    $router->post('/coupons/{coupon}', [CouponController::class, 'update'], ['auth', 'can:coupons.manage']);
    $router->post('/campaigns', [CouponController::class, 'saveCampaign'], ['auth', 'can:coupons.manage']);
    $router->post('/campaigns/{campaign}', [CouponController::class, 'saveCampaign'], ['auth', 'can:coupons.manage']);
    $router->get('/reports', [ReportController::class, 'index'], ['auth', 'can:reports.financial,reports.operational']);
    $router->get('/reports/{report}', [ReportController::class, 'show'], ['auth', 'can:reports.financial,reports.operational']);
    $router->get('/reports/{report}/csv', [ReportController::class, 'csv'], ['auth', 'can:reports.financial,reports.operational']);
    $router->get('/approvals', [ApprovalController::class, 'index'], ['auth']);
    $router->get('/project-templates', [ProjectKitController::class, 'templates'], ['auth', 'can:templates.manage,templates.apply']);
    $router->get('/project-templates/new', [ProjectKitController::class, 'templateForm'], ['auth', 'can:templates.manage']);
    $router->post('/project-templates', [ProjectKitController::class, 'saveTemplate'], ['auth', 'can:templates.manage']);
    $router->get('/project-templates/{template}', [ProjectKitController::class, 'templateForm'], ['auth', 'can:templates.manage']);
    $router->post('/project-templates/{template}', [ProjectKitController::class, 'saveTemplate'], ['auth', 'can:templates.manage']);
    $router->get('/projects/{project}/checklist', [ProjectKitController::class, 'checklist'], ['auth', 'project']);
    $router->post('/projects/{project}/checklist', [ProjectKitController::class, 'checklistAction'], ['auth', 'can:checklists.manage,templates.apply', 'project']);
    $router->get('/scope-requests', [ScopeRequestController::class, 'index'], ['auth', 'can:scope.assess']);
    $router->get('/scope-requests/{request}', [ScopeRequestController::class, 'show'], ['auth', 'can:scope.assess']);
    $router->post('/scope-requests/{request}', [ScopeRequestController::class, 'action'], ['auth', 'can:scope.assess']);
    $router->get('/my/scope-requests', [ScopeRequestController::class, 'myIndex'], ['auth', 'can:scope.request']);
    $router->get('/my/scope-requests/{request}', [ScopeRequestController::class, 'myShow'], ['auth', 'can:scope.request']);
    $router->post('/my/scope-requests/{request}/withdraw', [ScopeRequestController::class, 'withdraw'], ['auth', 'can:scope.request']);
    $router->get('/projects/{project}/scope-request', [ScopeRequestController::class, 'create'], ['auth', 'can:scope.request', 'project']);
    $router->post('/projects/{project}/scope-request', [ScopeRequestController::class, 'store'], ['auth', 'can:scope.request', 'project']);
    $router->post('/revisions/{revision}/feedback', [FeedbackController::class, 'add'], ['auth', 'can:feedback.add']);
    $router->post('/revisions/{revision}/feedback/{point}', [FeedbackController::class, 'resolve'], ['auth', 'can:feedback.resolve']);
    $ag = ['auth', 'can:agreements.view'];
    $router->get('/agreements', [AgreementController::class, 'index'], $ag);
    $router->get('/agreements/new', [AgreementController::class, 'create'], ['auth', 'can:agreements.manage']);
    $router->post('/agreements', [AgreementController::class, 'store'], ['auth', 'can:agreements.manage']);
    $router->get('/agreements/{agreement}', [AgreementController::class, 'show'], $ag);
    $router->post('/agreements/{agreement}', [AgreementController::class, 'action'], $ag);
    $router->get('/agreements/{agreement}/document', [AgreementController::class, 'document'], $ag);
    $router->get('/agreements/{agreement}/files/{version}', [AgreementController::class, 'file'], $ag);
    $router->get('/agreement-templates', [AgreementController::class, 'templates'], $ag);
    $router->post('/agreement-templates', [AgreementController::class, 'saveTemplate'], ['auth', 'can:agreements.manage']);
    $router->post('/agreement-templates/{template}', [AgreementController::class, 'saveTemplate'], ['auth', 'can:agreements.manage']);
    $router->get('/my/agreements', [AgreementController::class, 'myIndex'], ['auth', 'can:agreements.view_own']);
    $router->get('/my/agreements/{agreement}', [AgreementController::class, 'myShow'], ['auth', 'can:agreements.view_own']);
    $router->post('/my/agreements/{agreement}/sign', [AgreementController::class, 'mySign'], ['auth', 'can:agreements.sign']);
    $router->get('/my/agreements/{agreement}/document', [AgreementController::class, 'myDocument'], ['auth', 'can:agreements.view_own']);
    $router->get('/my/agreements/{agreement}/files/{version}', [AgreementController::class, 'myFile'], ['auth', 'can:agreements.view_own']);
    $fm = ['auth', 'can:forms.manage,forms.view_submissions'];
    $router->get('/forms', [FormController::class, 'index'], $fm);
    $router->get('/forms/new', [FormController::class, 'create'], ['auth', 'can:forms.manage']);
    $router->post('/forms', [FormController::class, 'store'], ['auth', 'can:forms.manage']);
    $router->get('/forms/{form}', [FormController::class, 'show'], $fm);
    $router->post('/forms/{form}', [FormController::class, 'update'], ['auth', 'can:forms.manage']);
    $router->post('/forms/{form}/fields', [FormController::class, 'addField'], ['auth', 'can:forms.manage']);
    $router->post('/forms/{form}/fields/{field}', [FormController::class, 'field'], ['auth', 'can:forms.manage']);
    $router->get('/forms/{form}/submissions', [FormController::class, 'submissions'], ['auth', 'can:forms.view_submissions']);
    $router->post('/forms/{form}/submissions/{sub}', [FormController::class, 'mark'], ['auth', 'can:forms.view_submissions']);
    $router->get('/forms/{form}/export', [FormController::class, 'export'], ['auth', 'can:forms.export']);
    $router->get('/f/{slug}', [DynamicFormController::class, 'show']);
    $router->post('/f/{slug}', [DynamicFormController::class, 'submit']);
    $router->get('/f/{slug}/thanks', [DynamicFormController::class, 'thanks']);
    $router->get('/my/invoices', [MyBillingController::class, 'invoices'], ['auth', 'can:invoices.view_own']);
    $router->get('/my/invoices/{invoice}', [MyBillingController::class, 'invoice'], ['auth', 'can:invoices.view_own']);
    $router->get('/my/invoices/{invoice}/print', [MyBillingController::class, 'print'], ['auth', 'can:invoices.view_own']);
    $router->post('/my/invoices/{invoice}/pay', [MyBillingController::class, 'claim'], ['auth', 'can:payments.pay']);
    // Online payments (Razorpay) are asked for by the KIS team only (amount typed by them); the client just sees and pays the request.
    $pr = ['auth', 'can:payments.request'];
    $router->get('/payment-requests', [PaymentRequestController::class, 'index'], $pr);
    $router->get('/invoices/{invoice}/pay-request', [PaymentRequestController::class, 'create'], $pr);
    $router->post('/invoices/{invoice}/pay-request', [PaymentRequestController::class, 'store'], $pr);
    $router->get('/renewals/{service}/pay-request', [PaymentRequestController::class, 'createRenewal'], $pr);
    $router->post('/renewals/{service}/pay-request', [PaymentRequestController::class, 'storeRenewal'], $pr);
    $router->get('/payment-requests/{order}', [PaymentRequestController::class, 'show'], $pr);
    $router->get('/payment-requests/{order}/status', [PaymentRequestController::class, 'status'], $pr);
    $router->post('/payment-requests/{order}/cancel', [PaymentRequestController::class, 'cancel'], $pr);
    $router->get('/my/pay/{order}', [PaymentRequestController::class, 'mine'], ['auth', 'can:invoices.view_own']);
    $router->post('/my/pay/{order}/checkout', [PaymentRequestController::class, 'checkout'], ['auth', 'can:invoices.view_own']);
    $router->post('/my/pay/{order}/verify', [PaymentRequestController::class, 'verify'], ['auth', 'can:invoices.view_own']);
    $router->get('/my/pay/{order}/status', [PaymentRequestController::class, 'mineStatus'], ['auth', 'can:invoices.view_own']);

    // AI assistance (drafts only)
    $router->get('/ai', [AiController::class, 'index'], ['auth', 'can:ai.use']);
    $router->post('/ai', [AiController::class, 'run'], ['auth', 'can:ai.use']);
    $router->post('/ai/tasks/create', [AiController::class, 'createTasks'], ['auth', 'can:ai.use']);
    $router->get('/my/quotes', [MyBillingController::class, 'quotes'], ['auth', 'can:quotes.view']);
    $router->get('/my/quotes/{quote}', [MyBillingController::class, 'quote'], ['auth', 'can:quotes.view']);
    $router->post('/my/quotes/{quote}', [MyBillingController::class, 'quoteDecision'], ['auth', 'can:quotes.respond']);
    $router->get('/my/services', [ClientServiceController::class, 'index'], ['auth', 'can:renewals.view_own']);
    $router->get('/my/services/{service}', [ClientServiceController::class, 'show'], ['auth', 'can:renewals.view_own']);

    // Support Centre: bug reports, support tickets, warranty (Phase 5). `can:` is the first gate only; TicketAccess decides per ticket (404).
    $tk = ['auth', 'can:tickets.view_own,tickets.view_all,tickets.view_assigned,tickets.view_technical'];
    $router->get('/support', [SupportController::class, 'home'], ['auth', 'can:tickets.view_own,support.dashboard']);
    $router->get('/support/bugs/new', [SupportController::class, 'bugForm'], ['auth', 'can:bugs.report']);
    $router->post('/support/bugs', [SupportController::class, 'bugStore'], ['auth', 'can:bugs.report']);
    $router->get('/support/tickets/new', [SupportController::class, 'ticketForm'], ['auth', 'can:tickets.create']);
    $router->post('/support/tickets', [SupportController::class, 'ticketStore'], ['auth', 'can:tickets.create']);
    $router->get('/support/warranty', [SupportController::class, 'warranty'], ['auth', 'can:warranty.view_own']);
    $router->get('/support/dashboard', [TicketController::class, 'dashboard'], ['auth', 'can:support.dashboard']);
    $router->get('/tickets', [TicketController::class, 'index'], $tk);
    $router->get('/tickets/{ticket}', [TicketController::class, 'show'], $tk);
    $router->post('/tickets/{ticket}/draft', [TicketController::class, 'draft'], ['auth', 'can:bugs.report']);
    $router->post('/tickets/{ticket}/reply', [TicketController::class, 'reply'], ['auth', 'can:tickets.reply_own,tickets.reply']);
    $router->post('/tickets/{ticket}/status', [TicketController::class, 'status'], $tk);
    $router->post('/tickets/{ticket}/assign', [TicketController::class, 'assign'], ['auth', 'can:tickets.assign']);
    $router->post('/tickets/{ticket}/severity', [TicketController::class, 'severity'], ['auth', 'can:bugs.manage']);
    $router->post('/tickets/{ticket}/priority', [TicketController::class, 'priority'], ['auth', 'can:bugs.manage,tickets.update_status']);
    $router->post('/tickets/{ticket}/escalate', [TicketController::class, 'escalate'], ['auth', 'can:tickets.escalate']);
    $router->post('/tickets/{ticket}/notes', [TicketController::class, 'note'], ['auth', 'can:tickets.note']);
    $router->post('/tickets/{ticket}/eligibility', [TicketController::class, 'eligibility'], ['auth', 'can:bugs.review_eligibility']);
    $router->post('/tickets/{ticket}/propose-change', [TicketController::class, 'proposeChange'], ['auth', 'can:tickets.propose_change']);
    $router->get('/tickets/{ticket}/files/{file}', [TicketController::class, 'file'], $tk);
    $router->get('/warranties', [WarrantyController::class, 'report'], ['auth', 'can:warranty.view']);
    $router->post('/warranties/terms', [WarrantyController::class, 'saveTerms'], ['auth', 'can:warranty.manage']);
    $router->get('/projects/{project}/warranty', [WarrantyController::class, 'project'], ['auth', 'can:warranty.view,warranty.view_own', 'project']);
    $router->post('/projects/{project}/warranty', [WarrantyController::class, 'save'], ['auth', 'can:warranty.manage', 'project']);
    $router->post('/projects/{project}/warranty/suspend', [WarrantyController::class, 'suspend'], ['auth', 'can:warranty.manage', 'project']);

    $router->get('/changes/{change}', [ChangeRequestController::class, 'show'], ['auth', 'can:revisions.manage,revisions.respond_cost']);
    $router->post('/changes/{change}', [ChangeRequestController::class, 'update'], ['auth', 'can:revisions.classify']);
    $router->post('/changes/{change}/send', [ChangeRequestController::class, 'send'], ['auth', 'can:revisions.classify']);
    $router->post('/changes/{change}/withdraw', [ChangeRequestController::class, 'withdraw'], ['auth', 'can:revisions.classify']);
    $router->post('/changes/{change}/decision', [ChangeRequestController::class, 'decide'], ['auth', 'can:revisions.respond_cost']);
    $router->get('/notifications', [NotificationController::class, 'index'], ['auth']);
    $router->post('/notifications/read', [NotificationController::class, 'read'], ['auth']);

    // Tasks, Kanban board, deadlines. Staff only (TaskPolicy keeps clients out even if a role were misconfigured).
    $router->get('/projects/{project}/tasks', [TaskController::class, 'projectTasks'], $p);
    $router->post('/projects/{project}/tasks', [TaskController::class, 'store'], ['auth', 'can:tasks.create', 'project']);
    $router->get('/team', [TeamController::class, 'index'], ['auth', 'can:projects.view_all']);
    $tk = ['auth', 'can:tasks.view'];
    $router->get('/tasks', [TaskController::class, 'index'], $tk);
    $router->get('/board', [TaskController::class, 'board'], $tk);
    $router->get('/deadlines', [TaskController::class, 'deadlines'], $tk);
    $router->get('/tasks/{task}', [TaskController::class, 'show'], $tk);
    $router->post('/tasks/{task}', [TaskController::class, 'update'], ['auth', 'can:tasks.edit']);
    $router->post('/tasks/{task}/status', [TaskController::class, 'status'], ['auth', 'can:tasks.edit,tasks.update_assigned,tasks.review']);
    $router->post('/tasks/{task}/assign', [TaskController::class, 'assign'], ['auth', 'can:tasks.assign']);
    $router->post('/tasks/{task}/comments', [TaskController::class, 'comment'], ['auth', 'can:tasks.comment']);
    $router->post('/tasks/{task}/time', [TaskController::class, 'time'], ['auth', 'can:tasks.log_time']);
    $router->post('/tasks/{task}/archive', [TaskController::class, 'archive'], ['auth', 'can:tasks.archive']);
    $router->post('/tasks/{task}/dependencies', [TaskController::class, 'addDependency'], ['auth', 'can:tasks.edit']);
    $router->post('/tasks/{task}/dependencies/{dep}/remove', [TaskController::class, 'removeDependency'], ['auth', 'can:tasks.edit']);
    $router->post('/tasks/{task}/attachments', [TaskController::class, 'attach'], ['auth', 'can:files.upload']);

    // Public website forms (anonymous): requirements, contact, appointment REQUEST. CSRF + rate limit + honeypot + consent.
    $router->get('/request-quote', [PublicFormController::class, 'requirements']);
    $router->post('/request-quote', [PublicFormController::class, 'submitRequirements']);
    $router->get('/contact', [PublicFormController::class, 'contact']);
    $router->post('/contact', [PublicFormController::class, 'submitContact']);
    $router->get('/book-appointment', [PublicFormController::class, 'appointment']);
    $router->post('/book-appointment', [PublicFormController::class, 'submitAppointment']);
    $router->get('/enquiry/received', [PublicFormController::class, 'received']);

    // CRM (staff). `can:` is the first gate; EnquiryPolicy + EnquiryService re-check access to the specific enquiry.
    $crm = ['auth', 'can:enquiries.view_all,enquiries.view_assigned'];
    $router->get('/crm', [CrmDashboardController::class, 'index'], $crm);
    $router->get('/crm/enquiries', [EnquiryController::class, 'index'], $crm);
    $router->get('/crm/enquiries/create', [EnquiryController::class, 'create'], ['auth', 'can:enquiries.create']);
    $router->post('/crm/enquiries', [EnquiryController::class, 'store'], ['auth', 'can:enquiries.create']);
    $router->get('/crm/enquiries/{enquiry}/files/{file}', [EnquiryFileController::class, 'download'], ['auth', 'can:enquiries.view_all,enquiries.view_assigned']);
    $router->get('/crm/enquiries/{enquiry}', [EnquiryController::class, 'show'], ['auth', 'can:enquiries.view_all,enquiries.view_assigned,enquiries.view_own']);
    $router->post('/crm/enquiries/{enquiry}', [EnquiryController::class, 'update'], ['auth', 'can:enquiries.edit']);
    $router->post('/crm/enquiries/{enquiry}/stage', [EnquiryController::class, 'stage'], ['auth', 'can:enquiries.move_stage']);
    $router->post('/crm/enquiries/{enquiry}/assign', [EnquiryController::class, 'assign'], ['auth', 'can:enquiries.assign']);
    $router->post('/crm/enquiries/{enquiry}/notes', [EnquiryController::class, 'note'], ['auth', 'can:enquiries.notes']);
    $router->post('/crm/enquiries/{enquiry}/contact', [EnquiryController::class, 'contact'], ['auth', 'can:enquiries.notes']);
    $router->post('/crm/enquiries/{enquiry}/followups', [EnquiryController::class, 'followup'], ['auth', 'can:enquiries.notes']);
    $router->post('/crm/enquiries/{enquiry}/followups/{followup}/complete', [EnquiryController::class, 'completeFollowup'], ['auth', 'can:enquiries.notes']);
    $router->post('/crm/enquiries/{enquiry}/duplicate', [EnquiryController::class, 'duplicate'], ['auth', 'can:enquiries.move_stage,enquiries.edit']);
    $router->post('/crm/enquiries/{enquiry}/convert', [EnquiryController::class, 'convert'], ['auth', 'can:enquiries.convert']);
    $router->post('/crm/enquiries/{enquiry}/archive', [EnquiryController::class, 'archive'], ['auth', 'can:enquiries.archive']);
    $router->post('/crm/enquiries/{enquiry}/attachments', [EnquiryController::class, 'attach'], ['auth', 'can:enquiries.edit']);
    $router->get('/attachments/{attachment}', [AttachmentController::class, 'download'], ['auth']);

    // Clients. Staff by ClientPolicy; the client role only reaches its own record (/my/company).
    $router->get('/my/company', [ClientController::class, 'mine'], ['auth', 'can:clients.view_own']);
    $router->get('/clients', [ClientController::class, 'index'], ['auth', 'can:clients.view_all,clients.view_assigned']);
    $router->get('/clients/create', [ClientController::class, 'create'], ['auth', 'can:clients.create']);
    $router->post('/clients', [ClientController::class, 'store'], ['auth', 'can:clients.create']);
    $router->get('/clients/{client}', [ClientController::class, 'show'], ['auth', 'can:clients.view_all,clients.view_assigned,clients.view_own']);
    $router->post('/clients/{client}', [ClientController::class, 'update'], ['auth', 'can:clients.edit']);
    $router->post('/clients/{client}/status', [ClientController::class, 'status'], ['auth', 'can:clients.edit']);
    $router->post('/clients/{client}/archive', [ClientController::class, 'archive'], ['auth', 'can:clients.archive']);
    $router->post('/clients/{client}/notes', [ClientController::class, 'note'], ['auth', 'can:clients.notes_add']);
    $router->post('/clients/{client}/merge', [ClientController::class, 'merge'], ['auth', 'can:clients.merge']);
    $router->post('/clients/{client}/invite', [ClientController::class, 'invite'], ['auth', 'can:clients.invite']);

    // Referral partners: their own submissions only.
    $router->get('/partner/referrals', [PartnerReferralController::class, 'index'], ['auth', 'can:enquiries.view_own']);
    $router->get('/partner/referrals/create', [PartnerReferralController::class, 'create'], ['auth', 'can:enquiries.submit_referral']);
    $router->post('/partner/referrals', [PartnerReferralController::class, 'store'], ['auth', 'can:enquiries.submit_referral']);
    $router->get('/partner/referrals/{enquiry}', [PartnerReferralController::class, 'show'], ['auth', 'can:enquiries.view_own,enquiries.view_all,enquiries.view_assigned']);

    // Administration
    $router->group('/admin', ['auth'], static function (Router $r): void {
        $r->get('/system', [SystemController::class, 'index'], ['can:settings.view']);
        $r->get('/settings', [SettingsController::class, 'index'], ['can:settings.view']);
        $r->post('/settings/{group}', [SettingsController::class, 'save'], ['can:settings.manage']);

        // Website content (services, team, partners, portfolio, openings, FAQs, policies, page sections) and job applications
        $r->get('/site', [SiteAdminController::class, 'index'], ['can:cms.view']);
        $r->post('/site/seed', [SiteAdminController::class, 'seed'], ['can:cms.publish']);
        $r->get('/site/sections/{page}', [SiteAdminController::class, 'sections'], ['can:cms.view']);
        $r->post('/site/sections/{page}', [SiteAdminController::class, 'saveSections'], ['can:cms.edit']);
        $r->post('/site/sections/{page}/reset', [SiteAdminController::class, 'resetSections'], ['can:cms.publish']);
        $r->get('/site/menus', [SiteAdminController::class, 'menus'], ['can:cms.view']);
        $r->post('/site/menus/{location}', [SiteAdminController::class, 'saveMenu'], ['can:cms.publish']);
        $r->post('/site/menus/{location}/reset', [SiteAdminController::class, 'resetMenu'], ['can:cms.publish']);
        $r->get('/site/hiring', [SiteAdminController::class, 'hiring'], ['can:cms.view']);
        $r->post('/site/hiring/state', [SiteAdminController::class, 'hiringState'], ['can:cms.publish']);
        $r->get('/site/{entity}', [SiteAdminController::class, 'list'], ['can:cms.view']);
        $r->get('/site/{entity}/new', [SiteAdminController::class, 'form'], ['can:cms.edit,legal.manage']);
        $r->post('/site/{entity}', [SiteAdminController::class, 'save'], ['can:cms.edit,legal.manage']);
        $r->get('/site/{entity}/{id}', [SiteAdminController::class, 'form'], ['can:cms.view,legal.manage']);
        $r->post('/site/{entity}/{id}', [SiteAdminController::class, 'save'], ['can:cms.edit,legal.manage']);
        $r->post('/site/{entity}/{id}/status', [SiteAdminController::class, 'status'], ['can:cms.publish,legal.manage']);
        $r->post('/site/{entity}/{id}/move', [SiteAdminController::class, 'move'], ['can:cms.edit,legal.manage']);
        $r->get('/site/policies/{id}/versions', [SiteAdminController::class, 'versions'], ['can:legal.manage,cms.view']);
        $r->post('/site/policies/{id}/versions/{version}/restore', [SiteAdminController::class, 'restoreVersion'], ['can:legal.manage']);
        $r->get('/careers', [CareerAdminController::class, 'index'], ['can:careers.view,careers.manage']);
        $r->get('/careers/{app}', [CareerAdminController::class, 'show'], ['can:careers.view,careers.manage']);
        $r->post('/careers/{app}', [CareerAdminController::class, 'status'], ['can:careers.manage']);
        $r->get('/careers/{app}/file', [CareerAdminController::class, 'file'], ['can:careers.view,careers.manage']);

        // Website / CMS (static paths first: {page} would otherwise swallow them)
        $r->get('/cms', [CmsController::class, 'index'], ['can:cms.view']);
        $r->post('/cms', [CmsController::class, 'create'], ['can:cms.edit']);
        $r->get('/cms/media', [CmsController::class, 'media'], ['can:cms.view']);
        $r->post('/cms/media', [CmsController::class, 'upload'], ['can:cms.edit']);
        $r->post('/cms/media/{media}/delete', [CmsController::class, 'deleteMedia'], ['can:cms.edit']);
        $r->get('/cms/menus', [CmsController::class, 'menus'], ['can:cms.view']);
        $r->post('/cms/menus/{location}', [CmsController::class, 'saveMenu'], ['can:cms.publish']);
        $r->get('/cms/redirects', [CmsController::class, 'redirects'], ['can:cms.view']);
        $r->post('/cms/redirects', [CmsController::class, 'addRedirect'], ['can:cms.publish']);
        $r->post('/cms/redirects/{id}/delete', [CmsController::class, 'deleteRedirect'], ['can:cms.publish']);
        $r->get('/cms/reusable', [CmsController::class, 'reusable'], ['can:cms.view']);
        $r->post('/cms/reusable', [CmsController::class, 'saveReusable'], ['can:cms.edit']);
        $r->get('/cms/reusable/{block}', [CmsController::class, 'reusable'], ['can:cms.view']);
        $r->post('/cms/reusable/{block}', [CmsController::class, 'saveReusable'], ['can:cms.edit']);
        $r->get('/cms/{page}', [CmsController::class, 'edit'], ['can:cms.view']);
        $r->get('/cms/{page}/preview', [CmsController::class, 'preview'], ['can:cms.view']);
        $r->get('/cms/{page}/versions', [CmsController::class, 'versions'], ['can:cms.view']);
        $r->post('/cms/{page}/versions/{version}/restore', [CmsController::class, 'restore'], ['can:cms.edit']);
        $r->post('/cms/{page}/details', [CmsController::class, 'details'], ['can:cms.edit']);
        $r->post('/cms/{page}/blocks', [CmsController::class, 'addBlock'], ['can:cms.edit']);
        $r->post('/cms/{page}/blocks/{index}', [CmsController::class, 'updateBlock'], ['can:cms.edit']);
        $r->post('/cms/{page}/blocks/{index}/action', [CmsController::class, 'blockAction'], ['can:cms.edit']);
        $r->post('/cms/{page}/status', [CmsController::class, 'status'], ['can:cms.publish']);
        $r->get('/business', [BillingSettingsController::class, 'business'], ['can:billing.configure']);
        $r->post('/business', [BillingSettingsController::class, 'saveBusiness'], ['can:billing.configure']);
        $r->get('/users', [UserController::class, 'index'], ['can:users.view']);
        $r->get('/users/create', [UserController::class, 'create'], ['can:users.create']);
        $r->post('/users', [UserController::class, 'store'], ['can:users.create']);
        $r->get('/users/{user}', [UserController::class, 'show'], ['can:users.view']);
        $r->post('/users/{user}', [UserController::class, 'update'], ['can:users.edit']);
        $r->post('/users/{user}/roles', [UserController::class, 'roles'], ['can:users.assign_roles']);
        $r->post('/users/{user}/deactivate', [UserController::class, 'deactivate'], ['can:users.deactivate']);
        $r->post('/users/{user}/reactivate', [UserController::class, 'reactivate'], ['can:users.deactivate']);
        $r->post('/users/{user}/archive', [UserController::class, 'archive'], ['can:users.archive']);
        $r->post('/users/{user}/send-reset', [UserController::class, 'sendReset'], ['can:users.reset_password']);
        $r->post('/users/{user}/reset-2fa', [UserController::class, 'reset2fa'], ['can:users.reset_2fa']);
        $r->post('/users/{user}/resend-invite', [UserController::class, 'resendInvite'], ['can:users.create']);

        $r->get('/automation', [AutomationController::class, 'index'], ['can:automation.manage']);
        $r->post('/automation', [AutomationController::class, 'save'], ['can:automation.manage']);
        $r->get('/automation/{rule}', [AutomationController::class, 'index'], ['can:automation.manage']);
        $r->post('/automation/{rule}', [AutomationController::class, 'save'], ['can:automation.manage']);
        $r->get('/sla', [AutomationController::class, 'sla'], ['can:sla.manage']);
        $r->post('/sla', [AutomationController::class, 'saveSla'], ['can:sla.manage']);
        $r->get('/recycle-bin', [RecycleBinController::class, 'index'], ['can:recycle.view']);
        $r->post('/recycle-bin/restore', [RecycleBinController::class, 'restore'], ['can:recycle.restore']);
        $r->get('/backup', [BackupController::class, 'index'], ['can:backups.manage']);
        $r->post('/backup/sql', [BackupController::class, 'download'], ['can:backups.manage']);
        $r->get('/data', [DataController::class, 'index'], ['can:data.export,data.import']);
        $r->get('/data/export/{type}', [DataController::class, 'export'], ['can:data.export']);
        $r->get('/data/template/{type}', [DataController::class, 'template'], ['can:data.import']);
        $r->post('/data/import/{type}', [DataController::class, 'import'], ['can:data.import']);

        $r->get('/roles', [RoleController::class, 'index'], ['can:roles.view']);
        $r->get('/roles/new', [RoleController::class, 'create'], ['can:roles.create']);
        $r->post('/roles', [RoleController::class, 'store'], ['can:roles.create']);
        $r->get('/roles/{role}', [RoleController::class, 'show'], ['can:roles.view']);
        $r->post('/roles/{role}/delete', [RoleController::class, 'destroy'], ['can:roles.create']);
        $r->post('/roles/{role}', [RoleController::class, 'update'], ['can:roles.manage']);

        $r->get('/crm-stages', [CrmStageController::class, 'index'], ['can:crm.configure']);
        $r->post('/crm-stages', [CrmStageController::class, 'update'], ['can:crm.configure']);

        $r->get('/pricing', [PricingController::class, 'rules'], ['can:pricing.view']);
        $r->post('/pricing', [PricingController::class, 'saveRule'], ['can:pricing.manage']);

        $r->get('/revision-policies', [RevisionTermsController::class, 'index'], ['can:revisions.policy,revisions.classify,revisions.override']);
        $r->post('/revision-policies', [RevisionTermsController::class, 'save'], ['can:revisions.policy']);

        $r->get('/audit', [AuditController::class, 'index'], ['can:audit.view']);
        $r->post('/audit/verify', [AuditController::class, 'verify'], ['can:audit.view']);
    });

    // Website pages from the CMS. These MUST stay last: every route above wins over a page with the same address (CmsService also refuses such addresses).
    $router->get('/{slug}', [PublicPageController::class, 'show'], ['nosession']);
    $router->get('/{section}/{slug}', [PublicPageController::class, 'show'], ['nosession']);
};
