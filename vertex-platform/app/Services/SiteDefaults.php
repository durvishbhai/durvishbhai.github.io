<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * The starting copy for the public website (supplied by the business) and the schema of every editable page section. Nothing here is a claim about results,
 * clients, awards, staff or prices: those only ever appear when an administrator adds and publishes them. {company} and {abbr} are replaced at display time
 * from the configured company name, so a rename never needs a content edit.
 */
final class SiteDefaults
{
    /** @return array{label:string,path:string,sections:array<string,array{label:string,fields:array<string,array<string,mixed>>,data:array<string,string>}>} */
    public static function page(string $page): array
    {
        return self::pages()[$page] ?? throw new \InvalidArgumentException("Unknown page {$page}");
    }

    private static function f(string $label, string $kind = 'text', int $max = 300, string $help = ''): array
    {
        return ['label' => $label, 'kind' => $kind, 'max' => $max, 'help' => $help];
    }

    /** @return array<string,array{label:string,path:string,sections:array<string,mixed>}> */
    public static function pages(): array
    {
        $btn = fn (string $l, string $u): array => ["{$l}_label" => self::f('Button text', 'text', 40), "{$l}_url" => self::f('Button address', 'url', 300, 'A page address like /contact or a full https:// address')];
        $cta = $btn('primary', '') + $btn('secondary', '');

        return [
            'home' => ['label' => 'Home page', 'path' => '/', 'sections' => [
                'hero' => ['label' => 'Hero', 'fields' => [
                    'eyebrow' => self::f('Small heading above the title', 'text', 80), 'heading' => self::f('Main heading', 'text', 160), 'subheading' => self::f('Sub-heading', 'textarea', 500),
                    'primary_label' => self::f('Main button text', 'text', 40), 'primary_url' => self::f('Main button address', 'url'), 'secondary_label' => self::f('Second button text', 'text', 40), 'secondary_url' => self::f('Second button address', 'url'),
                    'tertiary_label' => self::f('Third button text', 'text', 40), 'tertiary_url' => self::f('Third button address', 'url'), 'image' => self::f('Hero picture (optional)', 'media')],
                    'data' => ['eyebrow' => 'TECHNOLOGY. CREATIVITY. GROWTH.', 'heading' => 'Build Better. Create Smarter. Grow Faster.',
                        'subheading' => '{company} helps businesses turn their ideas into practical digital experiences through creative services, technology solutions and streamlined digital workflows.',
                        'primary_label' => 'Explore Our Services', 'primary_url' => '/services', 'secondary_label' => 'Start Your Project', 'secondary_url' => '/request-quote', 'tertiary_label' => 'Contact Our Team', 'tertiary_url' => '/contact', 'image' => '']],
                'intro' => ['label' => 'Introduction', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'body' => self::f('Text', 'textarea', 3000), 'cta_label' => self::f('Button text', 'text', 60), 'cta_url' => self::f('Button address', 'url')],
                    'data' => ['heading' => 'Your Ideas Deserve the Right Digital Partner.',
                        'body' => "Every business has different goals, challenges and opportunities. We bring creative thinking, digital expertise and a practical approach together to help businesses build their online presence, communicate effectively and improve the way they work.\n\nFrom content creation and graphic design to website development and AI-powered solutions, our focus is on understanding your requirements and delivering work aligned with your objectives.",
                        'cta_label' => 'Discover {company}', 'cta_url' => '/about']],
                'services' => ['label' => 'Services overview', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 600), 'button_label' => self::f('Button text', 'text', 60)],
                    'data' => ['heading' => 'Digital Services. One Connected Partner.', 'intro' => 'Whether you are building your brand, producing content, launching a website or exploring automation, our services are designed to support your digital journey.', 'button_label' => 'See all services']],
                'why' => ['label' => 'Why choose us', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'items' => self::f('Principles (one per line: Title | text)', 'lines', 3000)],
                    'data' => ['heading' => 'A Practical Approach to Digital Work.', 'items' => "Requirement-focused | We begin by understanding your objectives.\nClear communication | Keep expectations, deliverables and project progress understandable.\nOrganized execution | Use structured tasks, reviews and delivery processes.\nFlexible collaboration | Tailor the scope to the actual project.\nContinuous improvement | Improve our processes as the business grows."]],
                'how' => ['label' => 'How it works', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'items' => self::f('Steps (one per line: Title | text)', 'lines', 3000)],
                    'data' => ['heading' => 'From Idea to Delivery.', 'items' => "Tell Us Your Requirements | Explain your project and objectives.\nDiscuss the Scope | Define deliverables, timelines and pricing.\nBegin the Project | Work proceeds after the agreed terms and required payment.\nReview and Refine | Review eligible drafts and communicate permitted revisions.\nReceive Your Delivery | Access approved deliverables through the agreed delivery method or client portal."]],
                'portfolio' => ['label' => 'Portfolio preview', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 500), 'empty_text' => self::f('Text shown while no project is published', 'textarea', 500)],
                    'data' => ['heading' => 'Our Work and Creative Direction.', 'intro' => 'A selection of projects we are approved to show.', 'empty_text' => 'Our selected work will be shown here. In the meantime, tell us about your own project and let us discuss how we can help.']],
                'cta' => ['label' => 'Final call to action', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'body' => self::f('Text', 'textarea', 600)] + $cta,
                    'data' => ['heading' => 'Have a Project in Mind?', 'body' => 'Tell us what you are planning to build, improve or create. Share your requirements and let us understand how we may be able to help.', 'primary_label' => 'Start Your Project', 'primary_url' => '/request-quote', 'secondary_label' => 'Contact Us', 'secondary_url' => '/contact']],
            ]],
            'about' => ['label' => 'About Us', 'path' => '/about', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 1500)],
                    'data' => ['title' => 'About {company}', 'intro' => "{company} is a digital solutions initiative focused on creative services, technology and practical digital business support.\n\nWe aim to help businesses communicate their ideas, establish and improve their digital presence, and explore useful technology for their operations."]],
                'purpose' => ['label' => 'Our purpose', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 1500)], 'data' => ['heading' => 'Our purpose', 'body' => 'To make digital services and practical technology solutions more accessible, organized and useful for businesses.']],
                'vision' => ['label' => 'Our vision', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 1500)], 'data' => ['heading' => 'Our vision', 'body' => 'To build a dependable digital solutions company that grows through thoughtful execution, continuous learning and long-term business relationships.']],
                'mission' => ['label' => 'Our mission', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'items' => self::f('Points (one per line)', 'lines', 2000)],
                    'data' => ['heading' => 'Our mission', 'items' => "Understand each client's requirements before proposing a solution.\nDeliver organized creative and technical work.\nCommunicate project scope, timelines and deliverables clearly.\nUse appropriate technology to solve practical problems.\nBuild processes that support quality, accountability and continuous improvement."]],
                'principles' => ['label' => 'Working principles', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'items' => self::f('Principles (one per line)', 'lines', 1000)],
                    'data' => ['heading' => 'Our working principles', 'items' => "Clarity\nCreativity\nResponsibility\nCollaboration\nSecurity\nContinuous improvement"]],
                'what' => ['label' => 'What we do', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 1500)],
                    'data' => ['heading' => 'What we do', 'body' => 'We work across creative production, graphic design, content, websites and AI-assisted business solutions. The exact scope of each engagement depends on the agreed requirements and available services.']],
                'cta' => ['label' => 'Closing call to action', 'fields' => ['heading' => self::f('Heading', 'text', 160)] + $cta,
                    'data' => ['heading' => 'Looking for a digital partner for your next project?', 'primary_label' => 'Explore Our Services', 'primary_url' => '/services', 'secondary_label' => 'Get in Touch', 'secondary_url' => '/contact']],
            ]],
            'services' => ['label' => 'Services page', 'path' => '/services', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 800)],
                    'data' => ['title' => 'Our Services', 'intro' => 'Whether you are building your brand, producing content, launching a website or exploring automation, our services are designed to support your digital journey.']],
                'empty' => ['label' => 'Text shown while no service is published', 'fields' => ['body' => self::f('Text', 'textarea', 500)], 'data' => ['body' => 'Our published services will appear here. Please contact us to discuss what you need.']],
                'cta' => ['label' => 'Call to action', 'fields' => ['heading' => self::f('Heading', 'text', 160), 'button_label' => self::f('Button text', 'text', 60)], 'data' => ['heading' => 'Not sure which service fits?', 'button_label' => 'Request a Project Proposal']],
            ]],
            'team' => ['label' => 'Our Team page', 'path' => '/our-team', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 800)],
                    'data' => ['title' => 'Meet the People Behind the Work', 'intro' => 'Good digital work depends on thoughtful planning, creative problem-solving and collaboration. Our team structure brings together the skills required to support creative and technical projects.']],
                'empty' => ['label' => 'Text shown while no profile is published', 'fields' => ['body' => self::f('Text', 'textarea', 500)], 'data' => ['body' => 'We work as a collaborative team across creative production, development, operations and support. Team profiles will be added here when they are ready to be shown.']],
                'cta' => ['label' => 'Call to action', 'fields' => ['text' => self::f('Text', 'text', 200), 'button_label' => self::f('Button text', 'text', 60), 'button_url' => self::f('Button address', 'url')], 'data' => ['text' => 'Interested in working with us?', 'button_label' => 'Visit our Careers page', 'button_url' => '/careers']],
            ]],
            'partners' => ['label' => 'Our Partners page', 'path' => '/our-partners', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 1000)],
                    'data' => ['title' => 'Building Better Things Together', 'intro' => 'We believe collaboration can create opportunities to combine skills, exchange ideas and deliver better solutions. {company} welcomes suitable collaboration opportunities with businesses, creators, service providers and professionals.']],
                'areas' => ['label' => 'Collaboration areas', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'items' => self::f('Areas (one per line)', 'lines', 1500)],
                    'data' => ['heading' => 'Potential collaboration areas', 'items' => "Creative production\nWebsite and application development\nContent and marketing support\nTechnology and AI solutions\nProject-based service partnerships\nBusiness and referral collaborations"]],
                'apply' => ['label' => 'Become a partner', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 600), 'button_label' => self::f('Button text', 'text', 60), 'button_url' => self::f('Button address', 'url')],
                    'data' => ['heading' => 'Become a partner', 'body' => 'Interested in collaborating with {company}? Submit your details and tell us how you would like to work together.', 'button_label' => 'Apply to Become a Partner', 'button_url' => '/partners/apply']],
            ]],
            'clients' => ['label' => 'Our Clients page', 'path' => '/our-clients', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 1000)],
                    'data' => ['title' => 'Our Clients', 'intro' => '{company} works with clients from various countries. Here are some of the businesses and organisations we have had the privilege of working with.']],
                'empty' => ['label' => 'Text shown while no client is published', 'fields' => ['body' => self::f('Text', 'textarea', 500)],
                    'data' => ['body' => 'We work with clients from various countries. Our client list will be shown here as clients agree to be featured.']],
                'cta' => ['label' => 'Call to action', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 400), 'button_label' => self::f('Button text', 'text', 60), 'button_url' => self::f('Button address', 'url')],
                    'data' => ['heading' => 'Work with us', 'body' => 'Tell us about your project and we will review your requirements.', 'button_label' => 'Start Your Project', 'button_url' => '/request-quote']],
            ]],
            'portfolio' => ['label' => 'Portfolio page', 'path' => '/portfolio', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 800)],
                    'data' => ['title' => 'Selected Work', 'intro' => 'Explore selected projects that demonstrate our creative approach, technical work and range of services.']],
                'empty' => ['label' => 'Text shown while no project is published', 'fields' => ['heading' => self::f('Heading', 'text', 120), 'body' => self::f('Text', 'textarea', 500)],
                    'data' => ['heading' => 'Our selected work is coming soon', 'body' => 'We only show projects we are approved to share. Have something in mind? Tell us about your own project and let us discuss how we can help.']],
            ]],
            'careers' => ['label' => 'Careers page', 'path' => '/careers', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Page title', 'text', 160), 'hero_heading' => self::f('Heading', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 1200)],
                    'data' => ['title' => 'Build Your Skills. Create With Us.', 'hero_heading' => 'Explore Opportunities at {company}.',
                        'intro' => "We are interested in connecting with creative and technical professionals who value collaboration, responsibility and quality work.\n\nDepending on project requirements, opportunities may include freelance assignments, contract-based engagements and other roles approved by the company."]],
                'categories' => ['label' => 'Opportunity categories', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'note' => self::f('Note', 'textarea', 400), 'items' => self::f('Categories (one per line)', 'lines', 1500)],
                    'data' => ['heading' => 'Areas we work in', 'note' => 'These are the kinds of skills we work with. They are not a list of current vacancies.', 'items' => "Video Editor\nGraphic Designer\nWebsite Developer\nApplication Developer\nContent Writer\nSocial Media Professional\nAI and Automation Specialist"]],
                'openings' => ['label' => 'Current openings', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'empty_text' => self::f('Text shown when there is no opening', 'textarea', 400)],
                    'data' => ['heading' => 'Current openings', 'empty_text' => 'There are currently no published openings. You may check back later for new opportunities.']],
                'form' => ['label' => 'Application form', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'note' => self::f('Note under the form', 'textarea', 500)],
                    'data' => ['heading' => 'Send us your details', 'note' => 'Applications are subject to review and to the terms of the particular opportunity. Submitting an application does not guarantee employment, payment or selection.']],
            ]],
            'hiring' => ['label' => 'Hiring page', 'path' => '/hiring', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['eyebrow' => self::f('Small heading', 'text', 60), 'title' => self::f('Heading', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 800)],
                    'data' => ['eyebrow' => "WE'RE HIRING", 'title' => 'Build the Future With {company}', 'intro' => 'We are looking for skilled, reliable people to join us on real projects. Pick the role that fits you, tell us a little about yourself and we will be in touch.']],
                'roles' => ['label' => 'Open roles', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'items' => self::f('Roles (one per line: Title | Short description)', 'lines', 3000)],
                    'data' => ['heading' => 'Open roles', 'items' => "Full Stack Developer | Build and maintain complete web applications, from the interface to the server and database.\nFrontend Developer | Turn designs into fast, accessible and responsive user interfaces.\nBackend Developer | Build secure APIs, databases and the server-side logic behind our products.\nVideo Editor | Edit short-form and long-form videos with strong storytelling, pacing and polish."]],
                'terms' => ['label' => 'Salary and terms', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 800)],
                    'data' => ['heading' => 'Salary and terms', 'body' => 'Salary will be decided after the meeting. Submitting an application does not guarantee selection or employment.']],
                'process' => ['label' => 'How it works', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'items' => self::f('Steps (one per line: Title | Text)', 'lines', 2000)],
                    'data' => ['heading' => 'How it works', 'items' => "Apply | Choose a role and send us your details and, if you have one, your work.\nWe review | Our team reads every application.\nMeeting | If it looks like a fit, we arrange a meeting to talk about the work and the terms.\nSalary and decision | Salary is decided after the meeting, and we let you know our decision."]],
                'form' => ['label' => 'Application form', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'note' => self::f('Note under the form', 'textarea', 500)],
                    'data' => ['heading' => 'Apply now', 'note' => 'Your details are used only to review your application. Salary will be decided after the meeting.']],
                'closed' => ['label' => 'Message while hiring is closed', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'body' => self::f('Text', 'textarea', 600), 'button_label' => self::f('Button text', 'text', 60), 'button_url' => self::f('Button address', 'url')],
                    'data' => ['heading' => 'Hiring is currently closed', 'body' => 'We are not accepting applications right now. Thank you for your interest in {company}; please check back later.', 'button_label' => 'Back to home', 'button_url' => '/']],
            ]],
            'contact' => ['label' => 'Contact page', 'path' => '/contact', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 800)],
                    'data' => ['title' => "Let's Talk About Your Project", 'intro' => 'Have a question, need a digital service or want to discuss a potential collaboration? Send us a message and share what you have in mind.']],
                'form' => ['label' => 'Form', 'fields' => ['heading' => self::f('Heading', 'text', 100), 'project_note' => self::f('Line about the project form', 'text', 200)],
                    'data' => ['heading' => 'Send us a message', 'project_note' => 'Ready to start a project? Use the Project Requirements form.']],
            ]],
            'requirements' => ['label' => 'Project Requirements page', 'path' => '/request-quote', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 800)],
                    'data' => ['title' => 'Tell Us About Your Project', 'intro' => 'Help us understand what you want to build, the problem you want to solve and the outcome you expect. Provide as much detail as possible so we can evaluate your requirements. This is a request, not an order: no payment is taken.']],
            ]],
            'faq' => ['label' => 'FAQ page', 'path' => '/faq', 'sections' => [
                'header' => ['label' => 'Page heading', 'fields' => ['title' => self::f('Title', 'text', 160), 'intro' => self::f('Introduction', 'textarea', 600)],
                    'data' => ['title' => 'Frequently Asked Questions', 'intro' => 'Quick answers to common questions. Cannot find what you need? Contact us.']],
            ]],
        ];
    }

    /** @return list<array{slug:string,name:string,icon:string,summary:string,description:string,deliverables:string,use_cases:string}> */
    public static function services(): array
    {
        $s = static fn (string $slug, string $name, string $icon, string $summary, string $deliv, string $use): array => [
            'slug' => $slug, 'name' => $name, 'icon' => $icon, 'summary' => $summary,
            'description' => $summary . "\n\nThe exact scope, deliverables, timeline and price of every engagement are agreed with you in writing before work begins.",
            'deliverables' => $deliv, 'use_cases' => $use,
        ];

        return [
            $s('short-form-video-editing', 'Short-Form Video Editing', 'short', 'Create engaging short-form videos for reels, shorts and other social platforms.', "Edited short videos in the agreed format\nCaptions or on-screen text where requested\nExports for the platforms you name", "Social media campaigns\nProduct and announcement clips\nRepurposing longer videos into short clips"),
            $s('youtube-long-form-video-editing', 'YouTube and Long-Form Video Editing', 'youtube', 'Turn raw footage into structured, polished videos with an engaging narrative.', "Edited long-form video\nStructure, pacing and transitions agreed with you\nExport in the required format", "YouTube channels\nInterviews, tutorials and presentations\nEvent and promotional videos"),
            $s('social-media-management', 'Social Media Management', 'social', 'Support your online presence through content planning, publishing coordination and account management.', "Content planning\nPublishing coordination\nAccount management support as agreed", "Businesses starting or tidying up their social presence\nCampaign and announcement publishing"),
            $s('thumbnail-design', 'Thumbnail Design', 'image', 'Design attention-grabbing thumbnails that communicate the subject clearly.', "Thumbnail images in the agreed size\nSource files only if agreed", "Video channels\nCourse and tutorial series"),
            $s('poster-and-creative-design', 'Poster and Creative Design', 'poster', 'Create promotional graphics for campaigns, announcements and business communication.', "Poster and promotional graphics\nExports for print or digital use as agreed", "Campaign and event promotion\nAnnouncements and offers"),
            $s('graphic-design', 'Graphic Design', 'design', 'Develop visual assets that support your brand identity and communication.', "Visual assets for the agreed purpose\nFiles in the formats you need", "Brand and marketing materials\nPresentations and documents"),
            $s('content-writing', 'Content Writing', 'write', 'Prepare clear, audience-focused content for websites, campaigns and digital platforms.', "Written content for the agreed channel\nRevisions as permitted by the agreed scope", "Website pages\nCampaign copy and articles"),
            $s('website-development', 'Website Development', 'web', 'Build responsive websites and web-based experiences aligned with business requirements.', "A responsive website built to the agreed requirements\nHandover as agreed", "Business and portfolio websites\nWeb-based tools aligned with your workflow"),
            $s('ai-solutions', 'AI Solutions', 'spark', 'Explore practical AI-assisted tools and solutions for business workflows.', "An assessment of where AI-assisted tools can help\nImplementation of agreed solutions", "Drafting, summarising and research support\nInternal workflow assistance"),
            $s('ai-automation', 'AI Automation', 'gear', 'Identify repetitive processes that can be improved through suitable automation.', "Identification of suitable processes\nImplementation of agreed automation", "Repetitive administrative tasks\nConnecting routine steps between tools"),
        ];
    }

    /** @return list<array{category:string,question:string,answer:string}> */
    public static function faqs(): array
    {
        return [
            ['category' => 'Getting started', 'question' => 'How can I start a project?', 'answer' => 'Submit the Project Requirements form or contact us with your project details. We can review the information and discuss the scope, deliverables and next steps.'],
            ['category' => 'Services', 'question' => 'What services do you provide?', 'answer' => 'Our service categories may include video editing, graphic design, content writing, social media support, website development and AI-assisted solutions. Check the current Services page for published offerings.'],
            ['category' => 'Projects', 'question' => 'How do I receive project updates?', 'answer' => 'Where a client portal has been provided for your engagement, sign in to review the information and deliverables available to your account.'],
            ['category' => 'Projects', 'question' => 'Can I request revisions?', 'answer' => 'Revision eligibility and limits depend on the agreed scope and terms for your project. Use the relevant project communication or revision request option when available.'],
            ['category' => 'Payments', 'question' => 'How do payments work?', 'answer' => 'Payment terms, milestones, invoices and applicable payment methods are communicated for the particular engagement.'],
            ['category' => 'Support', 'question' => 'How can I contact support?', 'answer' => 'Use the Contact page or the support facility provided for your account, where available.'],
            ['category' => 'Opportunities', 'question' => 'How do I apply for an opportunity?', 'answer' => 'Visit the Careers page and apply to a published opening using its application instructions.'],
            ['category' => 'Opportunities', 'question' => 'Can I become a partner?', 'answer' => 'You can submit a partnership application through the Our Partners page. Applications are subject to review.'],
        ];
    }

    /** @return array{full_name:string,designation:string,bio:string,skills:string} the founder's profile (supplied by the business; edit or replace it under Website -> Team) */
    public static function founder(): array
    {
        return [
            'full_name' => 'Durvish Jawale',
            'designation' => 'Founder & CEO',
            'bio' => "Durvish Jawale is the Founder and CEO of The Young Books and Northform. A professional software developer, he combines hands-on engineering with business leadership, building practical web platforms and digital systems that help organisations work more clearly and grow with confidence.\n\nHe cares about clean, secure and maintainable software, honest communication with clients, and turning ideas into products that are ready for real-world use.",
            'skills' => 'Software development, Web applications, Product and business leadership',
        ];
    }

    /** @return list<array{slug:string,title:string,body:string}> */
    public static function policies(): array
    {
        $todo = static fn (string $what): string => "\n\n[ADMIN TO COMPLETE: {$what} Delete this whole line when done; the policy cannot be published while it is here.]";

        return [
            ['slug' => 'privacy-policy', 'title' => 'Privacy Policy', 'body' =>
                "{company} respects the privacy of individuals who visit its website, submit inquiries or use the services and account facilities made available through its platform.\n\n"
                . "Information may include contact details, project requirements, account information, communication records, uploaded documents, payment-related references and technical information needed to operate and secure the website.\n\n"
                . "Information is used for responding to inquiries, evaluating project requirements, providing agreed services, managing accounts, processing and verifying payments through applicable providers, maintaining records, providing support and protecting the platform.\n\n"
                . "Information may be shared with service providers where necessary to operate the service, process transactions, host the application, communicate with users or meet applicable legal obligations.\n\n"
                . "Reasonable technical and organizational measures should be used to protect personal information. No internet-based system can guarantee absolute security.\n\n"
                . "Users may contact the configured privacy contact to submit applicable privacy-related requests. Requests will be handled in accordance with applicable law."
                . $todo('Name the actual data controller and the privacy contact, the retention periods, the service providers used, the rights available to individuals and every other item your law requires, then have the policy reviewed.')],
            ['slug' => 'terms-and-conditions', 'title' => 'Terms and Conditions', 'body' =>
                "These terms govern access to the public website and use of the services offered by {company}.\n\n"
                . "Visitors must provide accurate information when submitting inquiries or applications and must not misuse the website, attempt unauthorized access, distribute malicious files or interfere with the platform.\n\n"
                . "The scope, deliverables, timelines, prices, revision limits, payment milestones and acceptance conditions for a particular project should be documented in the applicable proposal, invoice, order or agreement.\n\n"
                . "Clients are responsible for reviewing requirements and providing the materials, access and approvals reasonably needed for their projects.\n\n"
                . "Intellectual property ownership, licensing, confidentiality, cancellation, refunds, liability and dispute resolution must be governed by the applicable written agreement and relevant law."
                . $todo('Add the correct company identity, governing law, contact details and any other legal provisions, then have the terms reviewed.')],
            ['slug' => 'refund-and-cancellation-policy', 'title' => 'Refund and Cancellation Policy', 'body' =>
                "Cancellation and refund eligibility depends on the service purchased, the stage of work, the agreed scope and the applicable project agreement.\n\n"
                . "For project-based services, work may involve planning, resource allocation, production, third-party costs or completed deliverables. Any applicable cancellation charges, refund calculations and non-refundable costs must be disclosed in the relevant agreement or purchase terms before payment.\n\n"
                . "Clients who wish to cancel or request a refund should contact the configured support or billing channel and provide the relevant project or transaction reference.\n\n"
                . "Requests should be reviewed and documented. Approved refunds must be processed through the applicable payment method or provider, subject to its procedures and applicable law.\n\n"
                . "Nothing in this policy should exclude rights that cannot lawfully be excluded."
                . $todo('Confirm this matches your real refund practice and the law that applies to you. Do not publish a blanket "no refunds" statement unless it has been reviewed for your business.')],
            ['slug' => 'payment-policy', 'title' => 'Payment Policy', 'body' =>
                "Payment amounts, milestones, due dates and accepted payment methods are communicated through the applicable proposal, invoice or project agreement.\n\n"
                . "Where online payment is available, clients should use the payment option activated for their account or invoice. Payments must be verified through the applicable payment provider before being recorded as successful.\n\n"
                . "Clients should retain their transaction references and contact the billing team if a payment is delayed, duplicated or incorrectly recorded.\n\n"
                . "Advance payments, milestone payments, final payments, late payments, refunds and any applicable charges must follow the agreed project terms.\n\n"
                . "Hosting and domain renewals, where offered, must identify the applicable service, renewal period, price and due date. Automatic renewal should only be represented as available when the necessary provider integration and authorization are in place."
                . $todo('Check that this describes the payment methods and renewal practice you actually offer.')],
            ['slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'body' =>
                "The website may use essential cookies or similar technologies needed for security, session management, authentication and core website functionality.\n\n"
                . "Analytics, advertising and other non-essential technologies should only be enabled according to the site's actual configuration and applicable consent requirements.\n\n"
                . "Users should be provided with clear information about relevant cookie categories and available controls where required."
                . $todo('List the cookies, scripts and third-party services this website really uses. If it uses only essential cookies, say so.')],
        ];
    }
}
