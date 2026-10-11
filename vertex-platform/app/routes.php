<?php

declare(strict_types=1);

use Vertex\Core\Router;
use Vertex\Http\Controllers\AccountController;
use Vertex\Http\Controllers\Admin\AuditController;
use Vertex\Http\Controllers\Admin\RevisionTermsController;
use Vertex\Http\Controllers\Admin\RoleController;
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
use Vertex\Http\Controllers\RegistrationController;

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

    // Authentication
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest']);
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
    $router->get('/account', [AccountController::class, 'show'], ['auth']);
    $router->post('/account', [AccountController::class, 'update'], ['auth']);
    $router->post('/account/password', [AccountController::class, 'password'], ['auth']);

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
        $r->post('/users/{user}/resend-invite', [UserController::class, 'resendInvite'], ['can:users.create']);

        $r->get('/roles', [RoleController::class, 'index'], ['can:roles.view']);
        $r->get('/roles/{role}', [RoleController::class, 'show'], ['can:roles.view']);
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
};
