<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * The starting drafts of the legal documents. They are written for the real KIS business (see docs/29): a business unit operated under a sole proprietorship, the
 * quotation → 50% advance via Razorpay → confirmed order workflow, delivery-date booking, revisions within 14 days, domain/hosting options, 18+ customers.
 *
 * They are DRAFTS: nothing is published until the Owner has filled in the facts only they know (shown as {{tokens}}) and has had a lawyer review the clauses listed in
 * `flags`. Nothing here claims a legal compliance, certification or guarantee. The sections listed in `sections` can never be removed from a version.
 */
final class LegalDefaults
{
    /** @return list<array{key:string,title:string,kind:string,acceptance:bool,mandatory:bool,footer:bool,sections:list<array{id:string,title:string}>,flags:list<array{clause:string,why:string}>,body:string}> */
    public static function documents(): array
    {
        $sec = static fn (string ...$titles): array => array_map(static fn (string $t): array => ['id' => HtmlSanitizer::slug($t), 'title' => $t], $titles);

        return [
            [
                'key' => 'privacy-policy', 'title' => 'Privacy Policy', 'kind' => 'policy', 'acceptance' => true, 'mandatory' => true, 'footer' => true,
                'sections' => $sec('Who we are', 'Information we collect', 'Why we use it', 'Who we share it with', 'How long we keep it', 'Your rights and deleting your data', 'Children', 'International processing', 'Contact and complaints'),
                'flags' => [
                    ['clause' => 'Legal basis and DPDP wording', 'why' => 'The Digital Personal Data Protection Act, 2023 and the DPDP Rules, 2025 phase in over about 18 months from November 2025. Confirm which provisions apply to KIS now and the exact notice/consent wording.'],
                    ['clause' => 'International processing', 'why' => 'Processing for clients abroad may bring in other regimes (for example GDPR/UK GDPR if people in those places are targeted). Confirm whether any applies and whether transfer wording is needed.'],
                    ['clause' => 'Retention period', 'why' => 'The number of years records are kept is a placeholder. Confirm with your accountant and lawyer.'],
                ],
                'body' => <<<'HTML'
<p>This policy explains how {{brand}} collects, uses and protects personal data. It applies to this website, the client portal and the communications we send.</p>
<h2>Who we are</h2>
<p>{{business_disclosure}} The legal proprietor of {{legal_enterprise}} is {{proprietor}}. {{brand}} is managed and operated day to day by {{operator}} ({{operator_role}}). You can reach us at {{email}} or on WhatsApp {{whatsapp}}.</p>
<h2>Information we collect</h2>
<p>We collect only what we need for the purpose in front of you:</p>
<ul>
<li><strong>Identity and contact:</strong> full name, email address, phone/WhatsApp number, company or business name, country and city (and an address only where an invoice or agreement needs it).</li>
<li><strong>Project information:</strong> your requirements, budget range, deadlines and the reference documents or images you choose to upload.</li>
<li><strong>Account information:</strong> your login email, a securely hashed password, security settings (such as two-factor sign-in) and sign-in activity.</li>
<li><strong>Payment information:</strong> the amount, date, status and reference of a payment. Card, UPI and bank details are entered on Razorpay's secure payment window; we do not see or store full card numbers.</li>
<li><strong>Messages:</strong> what you write in the contact form, by email or in the portal. WhatsApp conversations stay in WhatsApp under its own terms.</li>
<li><strong>Technical data:</strong> your IP address, browser type and the pages and actions needed to keep the service secure. We use first-party cookies only for sign-in, security and your saved choices (see the Cookie Policy). We do not run analytics or advertising trackers at present.</li>
</ul>
<h2>Why we use it</h2>
<ul>
<li>To answer your enquiry, prepare a quotation and, if you go ahead, deliver and support your project.</li>
<li>To take and verify payments, issue invoices and receipts and keep the records the law requires.</li>
<li>To keep accounts and the website secure, prevent fraud and abuse, and fix faults.</li>
<li>To send you service messages (quotations, payment receipts, security alerts, delivery appointments, policy changes). These are not marketing.</li>
<li>To send marketing email only if you separately opt in. We do not send marketing email at present.</li>
</ul>
<h2>Who we share it with</h2>
<p>We do not sell personal data. We use service providers that process data for us, listed with their purpose on the Third-Party Services page: our hosting provider (Hostinger), the payment processor (Razorpay), email delivery (Gmail SMTP) and, if you choose Google sign-in, Google. We may disclose data where the law requires or to protect our legal rights. Staff and authorised representatives see only the client information needed for their work.</p>
<h2>How long we keep it</h2>
<p>We keep client information until you ask us to delete it. Invoices, quotations, payment receipts, signed agreements and the project records needed to support them are kept for the periods required by applicable law, even after a deletion request. Enquiries that did not become orders, optional profile details, notifications and uploaded reference files are deleted or anonymised when you ask.</p>
<h2>Your rights and deleting your data</h2>
<p>You can ask to see, correct, update or delete the personal data we hold, withdraw consent you gave, and complain. Use the Data Deletion Request page to ask for deletion: we confirm your identity, then tell you what was deleted, what was anonymised and what must be kept, and why. We aim to answer within {{deletion_days}} days. Withdrawing consent does not affect what we did before you withdrew it, and does not remove records we must keep by law.</p>
<h2>Children</h2>
<p>Our services are for people aged {{min_age}} and over, and for businesses and organisations acting through an authorised representative. We do not knowingly collect personal data from anyone under {{min_age}}. If you believe a child has sent us information, contact us and we will delete it.</p>
<h2>International processing</h2>
<p>We serve clients worldwide. Our providers may process data in countries other than yours, including India. We choose providers with appropriate security commitments and share only what is needed. If you are outside India, the laws of your country may give you additional rights; tell us and we will consider them.</p>
<h2>Contact and complaints</h2>
<p>Questions, requests and complaints about privacy: {{grievance_contact}}. We aim to acknowledge grievances within {{grievance_days}} days. We will update this policy when our practices change; the version and effective date are shown at the top, and clients are told about material changes.</p>
HTML,
            ],
            [
                'key' => 'terms-and-conditions', 'title' => 'Terms and Conditions', 'kind' => 'policy', 'acceptance' => true, 'mandatory' => true, 'footer' => true,
                'sections' => $sec('About these terms', 'Using the website', 'Eligibility', 'Enquiries and quotations', 'Order confirmation and advance payment', 'Project scheduling and delivery', 'Client responsibilities', 'Revisions', 'Agreements', 'Intellectual property', 'Domain and hosting', 'Third-party services', 'Communication', 'Portfolio', 'Limitation of liability', 'Disputes and governing law'),
                'flags' => [
                    ['clause' => 'Limitation of liability', 'why' => 'Caps and exclusions must be reasonable and cannot remove rights under the Consumer Protection Act, 2019 where it applies, or liability for fraud or wilful misconduct. Confirm wording and the cap.'],
                    ['clause' => 'Governing law, courts and dispute resolution', 'why' => 'The place of jurisdiction and any arbitration/mediation step must be chosen with a lawyer; for consumers abroad local mandatory rules may still apply.'],
                    ['clause' => 'Suspension for non-payment of renewals', 'why' => 'Confirm the notice period and that domains the client owns are never held back over unrelated disputes.'],
                    ['clause' => 'Electronic records and signatures', 'why' => 'Confirm how electronic acceptance and any scanned or electronic signatures are treated under the IT Act, 2000 and the Indian Contract Act, 1872.'],
                ],
                'body' => <<<'HTML'
<p>These terms govern your use of this website and the services supplied by {{brand}}. {{business_disclosure}} Contracts are made with {{legal_enterprise}}, whose legal proprietor is {{proprietor}}. By using the website or placing an order you agree to these terms.</p>
<h2>About these terms</h2>
<p>Each project is also covered by the quotation you accept and the final agreement described below. If they differ, the signed agreement applies first, then the accepted quotation, then these terms. Nothing here removes rights that the law gives you and that cannot be excluded.</p>
<h2>Using the website</h2>
<p>Use the website lawfully. Do not attempt to break its security, overload it, copy it automatically, upload malicious files or submit false information. We may suspend access to protect the service or other users.</p>
<h2>Eligibility</h2>
<p>Our services are for people aged {{min_age}} and over and for businesses and organisations acting through an authorised representative. By submitting an enquiry you confirm you meet this requirement.</p>
<h2>Enquiries and quotations</h2>
<p>An enquiry or requirements form is a request for a discussion and a quotation. It is not an order. After we discuss your requirements we prepare a final quotation. A quotation is only an offer until you accept it, and states its scope, price and validity. Statements on the website, in a chat or in an estimate are not guarantees of price or outcome.</p>
<h2>Order confirmation and advance payment</h2>
<p>Our workflow is: (1) you submit the Project Requirements Form; (2) we discuss the requirements; (3) we prepare and share a final quotation; (4) you review and accept it; (5) you pay the standard advance of {{advance_percent}}% of the total project value through Razorpay; (6) after we have verified that the payment succeeded, the order is confirmed; (7) we schedule the work and the delivery date. An order is never confirmed on an unverified payment attempt. The remaining balance is payable as stated in the quotation and the final agreement.</p>
<h2>Project scheduling and delivery</h2>
<p>We set, for each project, the preparation time we need. After that period you choose a delivery date on the delivery calendar, which is switched on for your project by us. You choose the delivery date only. Each day has limited capacity, so a date can be unavailable. If you delay giving content, documents, approvals or other materials we reasonably need, the delivery timeline can be extended by a reasonable period. If we face an unavoidable technical problem or an emergency, we may propose a revised delivery date and will tell you beforehand with an explanation.</p>
<h2>Client responsibilities</h2>
<p>You must give accurate information, provide content and approvals on time, hold the rights to what you give us, keep your account details safe and tell us about changes that affect the project.</p>
<h2>Revisions</h2>
<p>Revision requests are accepted within {{revision_days}} days of the applicable delivery or completion milestone. After that, revisions are not included. Revision work can take additional time, and we will tell you the scope and timeline. Changes outside the agreed requirements may need additional charges and a separate quotation.</p>
<h2>Agreements</h2>
<p>Every engagement is covered by a final agreement. One agreement may cover several orders of the same client engagement (for example a website, a logo and an Android app). The parties are the client, the authorised representative of {{brand}} and the legal proprietor of {{legal_enterprise}}. It may be signed on paper, through an electronic-signature service, or by scanned copies of a physically signed document. We keep evidence of how each signature was given, and we do not describe a pasted signature picture as a certified digital signature. The agreement is completed before final delivery and final payment.</p>
<h2>Intellectual property</h2>
<p>After full payment, you own the agreed final deliverables and we give you the agreed source code. Ownership is subject to third-party and open-source licences, to pre-existing intellectual property of ours or others, and to reusable frameworks and components that are not specifically transferred. We do not claim to own third-party assets and you must not treat them as yours beyond their licence.</p>
<h2>Domain and hosting</h2>
<p>Three arrangements are offered: <strong>KIS managed</strong> (we manage the domain and hosting providers; you pay annual renewals to us and we pay the providers); <strong>KIS managed with client access</strong> (as before, and you may ask for access to the domain/hosting accounts); and <strong>client managed</strong> (you choose and manage the providers and renewals, and we give you the agreed source code and deliverables). Domain and hosting renewal charges are borne by the client, and we tell you of price changes. Please pay at least one day before the renewal date, preferably earlier. If a renewal is not paid we may suspend the service after appropriate notice. We will help with domain transfer and migration, and we will not hold a domain that belongs to you back because of an unrelated payment dispute.</p>
<h2>Third-party services</h2>
<p>Projects may rely on third-party services (hosting, domains, payment gateways, email, APIs). They have their own terms and prices, and we are not responsible for their outages or changes, though we will help you respond.</p>
<h2>Communication</h2>
<p>WhatsApp is our primary channel for project communication. You can also use the website contact form or our business email ({{email}}; WhatsApp {{whatsapp}}). Please keep project instructions in writing on one of these channels.</p>
<h2>Portfolio</h2>
<p>We show a completed client project in our portfolio only with the client's permission.</p>
<h2>Limitation of liability</h2>
<p>To the extent the law allows, we are not liable for indirect or consequential loss, or for loss caused by things outside our reasonable control, and our total liability for a project is limited to the amount you paid for it. Nothing limits liability that cannot be limited by law, including for fraud.</p>
<h2>Disputes and governing law</h2>
<p>Please contact us first so we can try to resolve a problem informally. These terms are governed by the laws of India. The courts at {{jurisdiction}} have jurisdiction, subject to any mandatory rights you have as a consumer. Complaints: {{grievance_contact}}.</p>
HTML,
            ],
            [
                'key' => 'refund-policy', 'title' => 'Refund Policy', 'kind' => 'policy', 'acceptance' => true, 'mandatory' => true, 'footer' => true,
                'sections' => $sec('When this policy applies', 'Before the project starts', 'After the project starts', 'If we cannot complete a project', 'Your legal rights', 'How refunds are paid'),
                'flags' => [
                    ['clause' => 'Retaining half of the advance on cancellation before commencement', 'why' => 'Under Indian contract law a retained sum must be reasonable compensation, not a penalty (Indian Contract Act s.74). The sum is justified here as preparation and resource arrangements; confirm it is defensible, and that it is clearly disclosed before payment.'],
                    ['clause' => 'No refund after the project starts', 'why' => 'A blanket exclusion may be an unfair contract term, and it cannot take away remedies for defective or undelivered services under the Consumer Protection Act, 2019 (where the client is a consumer). The text keeps legal rights and the service-failure route; confirm it.'],
                    ['clause' => 'Refunds to international clients', 'why' => 'Payment-gateway rules, currency conversion and local consumer rules may affect timing and amount.'],
                ],
                'body' => <<<'HTML'
<p>This policy explains what happens to your payment if you cancel or if we cannot complete a project. It is part of the agreement for every order. It is {{brand}}'s stated commercial policy; it does not replace any right the law gives you.</p>
<h2>When this policy applies</h2>
<p>It applies once you have accepted a quotation and paid the standard advance of {{advance_percent}}% of the total project value. Before payment there is nothing to refund. An order is confirmed only after we verify the payment.</p>
<h2>Before the project starts</h2>
<p>If you cancel after paying the advance and before the project start date, we propose to return 50% of the advance. We keep the other 50% of the advance for the preparation and resource arrangements we have made for your project. Example: total project value ₹20,000; advance ₹10,000; cancellation before the project starts; proposed refund ₹5,000; retained ₹5,000. We will confirm the figures to you in writing before we process anything.</p>
<h2>After the project starts</h2>
<p>Once work on the project has started, our stated policy is that the advance is not refundable, because the time and resources have been committed to your project. This does not limit any right you have under the law, and we will consider each case on its facts.</p>
<h2>If we cannot complete a project</h2>
<p>If we cannot complete a project for our own reasons, we will aim to offer an alternative timeline or a mutually agreed solution and will make reasonable efforts to complete the work. If that is not possible, we will discuss a fair refund of what you paid for work not delivered.</p>
<h2>Your legal rights</h2>
<p>Nothing in this policy excludes or limits rights that you have under applicable law, including consumer-protection law, that cannot lawfully be excluded. Where the law requires a refund, we will provide it.</p>
<h2>How refunds are paid</h2>
<p>Approved refunds are returned to the original payment method through Razorpay or, where that is not possible, by another method we agree with you. Banks and gateways may take several working days. Questions: {{email}} or WhatsApp {{whatsapp}}.</p>
HTML,
            ],
            [
                'key' => 'cancellation-policy', 'title' => 'Cancellation Policy', 'kind' => 'policy', 'acceptance' => true, 'mandatory' => true, 'footer' => true,
                'sections' => $sec('How to cancel', 'Cancelling before the project starts', 'Cancelling after the project starts', 'Changes to delivery dates', 'Cancellation by us'),
                'flags' => [
                    ['clause' => 'Effect of cancellation on delivery appointments and agreement', 'why' => 'Confirm how a cancellation interacts with a signed agreement covering several orders.'],
                ],
                'body' => <<<'HTML'
<p>This policy explains how to cancel an order or a delivery appointment with {{brand}}. Money consequences are in the Refund Policy.</p>
<h2>How to cancel</h2>
<p>Tell us in writing by WhatsApp ({{whatsapp}}), email ({{email}}) or the contact form, with your name, project and the order you want to cancel. We confirm the cancellation in writing.</p>
<h2>Cancelling before the project starts</h2>
<p>You may cancel before the project start date. If you have paid the advance, the Refund Policy explains the proposed refund and the part that is retained for preparation and resource arrangements. Any delivery appointment is released.</p>
<h2>Cancelling after the project starts</h2>
<p>After the project has started, our stated policy is that the advance is not refundable; see the Refund Policy and your legal rights. We can discuss pausing the project or changing its scope instead.</p>
<h2>Changes to delivery dates</h2>
<p>You can move or cancel a delivery appointment yourself in your dashboard until a few days before it (the cut-off is shown there). After that, send us a request and we will help. If you delay giving content, documents or approvals, we may extend the timeline by a reasonable period.</p>
<h2>Cancellation by us</h2>
<p>We may cancel or pause an order if you do not provide what we need after reasonable reminders, if the work would be unlawful, or if payments due are not made after notice. We will tell you why and discuss a fair outcome. If we cannot complete a project for our own reasons we follow the Refund Policy.</p>
HTML,
            ],
            [
                'key' => 'cookie-policy', 'title' => 'Cookie Policy', 'kind' => 'policy', 'acceptance' => false, 'mandatory' => true, 'footer' => true,
                'sections' => $sec('What cookies are', 'Cookies we use', 'Your choices', 'Third-party cookies'),
                'flags' => [['clause' => 'Cookie categories and consent', 'why' => 'Confirm that the categories and consent approach suit the jurisdictions of your visitors.']],
                'body' => <<<'HTML'
<p>This policy explains the cookies and similar storage used by the {{brand}} website.</p>
<h2>What cookies are</h2>
<p>Cookies are small files stored in your browser. Some are needed for a website to work; others remember choices or measure use.</p>
<h2>Cookies we use</h2>
<p>The exact list, with purposes and lifetimes, is on the Cookie Settings page and is kept up to date. Today we use only <strong>strictly necessary</strong> cookies: a session cookie (keeps you signed in and holds form protection), a security token, and your saved cookie choices. We may store your colour theme choice in your browser. We do not use analytics, advertising or tracking cookies at present.</p>
<h2>Your choices</h2>
<p>You can accept all, reject non-essential cookies, or choose categories (strictly necessary, preferences, analytics, marketing) in the banner, and change or withdraw your choice at any time from "Cookie Settings" in the website footer. Optional categories stay off until you choose them. Blocking strictly necessary cookies may stop sign-in and forms from working.</p>
<h2>Third-party cookies</h2>
<p>If we introduce a service that sets its own cookies, it will be listed on the Third-Party Services page and will not run until you have agreed to its category. Razorpay's payment window, which opens only when you pay, is served by Razorpay under its own policy.</p>
HTML,
            ],
            [
                'key' => 'payment-policy', 'title' => 'Payment Policy', 'kind' => 'policy', 'acceptance' => false, 'mandatory' => false, 'footer' => true,
                'sections' => $sec('How payments work', 'Taxes and invoices', 'Failed or disputed payments'),
                'flags' => [['clause' => 'Tax wording', 'why' => 'The business is not currently registered for GST. Confirm how invoices should describe taxes and when registration becomes necessary.']],
                'body' => <<<'HTML'
<p>How you pay {{brand}} and what happens to a payment.</p>
<h2>How payments work</h2>
<p>After you accept a quotation you pay the standard advance of {{advance_percent}}% through Razorpay (cards, UPI, net banking and wallets as offered there). The order is confirmed only after we verify that the payment succeeded. We may also accept an agreed UPI transfer, which is confirmed by our team after we check the payment. The balance and any renewals are paid as stated in your quotation or agreement. Every verified payment gets a receipt.</p>
<h2>Taxes and invoices</h2>
<p>{{gst_status}}. Invoices show what the quotation states. If this changes, new invoices will show it from the date of change.</p>
<h2>Failed or disputed payments</h2>
<p>If a payment fails or is reversed, the order is not confirmed. If you were charged twice or something looks wrong, write to {{email}} with the reference. Refunds are covered by the Refund Policy.</p>
HTML,
            ],
            [
                'key' => 'accessibility-statement', 'title' => 'Accessibility Statement', 'kind' => 'statement', 'acceptance' => false, 'mandatory' => true, 'footer' => true,
                'sections' => $sec('Our aim', 'What we do', 'Known limitations', 'Feedback and contact'),
                'flags' => [['clause' => 'Accessibility standard wording', 'why' => 'Confirm any accessibility obligations that apply (for example the Rights of Persons with Disabilities Act, 2016 and related guidelines) and update the statement after any independent audit.']],
                'body' => <<<'HTML'
<p>{{brand}} wants its website to be usable by as many people as possible.</p>
<h2>Our aim</h2>
<p>We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA. We have not had an independent accessibility audit and we do not claim certification or full conformance.</p>
<h2>What we do</h2>
<p>We use semantic page structure and headings, a "skip to content" link, labelled forms with errors tied to their fields, visible keyboard focus, text alternatives for meaningful images (and empty alternatives for decorative ones), colour contrast checked against WCAG AA, support for reduced motion, and layouts that work on phones and tablets. The cookie banner and dialogs can be used with a keyboard. We do not publish audio or video; if we do, we will provide captions or transcripts.</p>
<h2>Known limitations</h2>
<p>The current list of known limitations is kept in the Owner's compliance settings and shown here when it is not empty. Content added by clients in their private portal is theirs, and we cannot always control its accessibility.</p>
<h2>Feedback and contact</h2>
<p>If something is hard to use, tell us at {{email}} or on WhatsApp {{whatsapp}} and we will try to help and to fix it.</p>
HTML,
            ],
            [
                'key' => 'security-disclosure', 'title' => 'Security Disclosure', 'kind' => 'statement', 'acceptance' => false, 'mandatory' => false, 'footer' => false,
                'sections' => $sec('How we protect the website', 'Reporting a problem'),
                'flags' => [],
                'body' => <<<'HTML'
<p>What we do to keep your information safe, and how to report a problem.</p>
<h2>How we protect the website</h2>
<p>The website uses HTTPS, protected sign-in with optional two-factor authentication, rate limiting against password guessing and automated abuse, protection against request forgery, strict content-security rules, safe handling of uploaded files, access controls based on roles, and an audit log of sensitive actions. Passwords are stored only as secure hashes. Payment card details are handled by Razorpay, not by us. No internet service can promise perfect security.</p>
<h2>Reporting a problem</h2>
<p>If you find a security weakness, please write to {{email}} with the details and do not publish it until we have had a chance to fix it. We will acknowledge your report and keep you informed.</p>
HTML,
            ],
        ];
    }
}
