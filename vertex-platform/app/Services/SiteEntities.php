<?php

declare(strict_types=1);

namespace Vertex\Services;

/** The shape of every editable website list (services, team, partners, portfolio, openings, FAQs, policies): fields, labels and rules. The admin forms and the validation both read this. */
final class SiteEntities
{
    private static function t(string $label, int $max, bool $req = false, string $help = ''): array
    {
        return ['label' => $label, 'kind' => 'text', 'max' => $max, 'required' => $req, 'help' => $help];
    }

    private static function a(string $label, int $max, bool $req = false, string $help = '', string $kind = 'textarea'): array
    {
        return ['label' => $label, 'kind' => $kind, 'max' => $max, 'required' => $req, 'help' => $help];
    }

    /** @return array<string,array<string,mixed>> */
    public static function defs(): array
    {
        $seo = ['seo_title' => self::t('Search-engine title (optional)', 160), 'seo_description' => self::t('Search-engine description (optional)', 320)];

        return [
            'services' => ['label' => 'Services', 'singular' => 'service', 'table' => 'site_services', 'title' => 'name', 'slug' => true, 'statuses' => ['draft', 'published', 'archived'], 'public' => '/services/', 'edit' => 'cms.edit', 'publish' => 'cms.publish',
                'help' => 'Every published service appears on the Services page and gets its own page. Do not publish a service you are not offering.',
                'fields' => [
                    'name' => self::t('Service name', 120, true), 'icon' => ['label' => 'Icon', 'kind' => 'select', 'options' => SiteText::iconChoices(), 'required' => true],
                    'summary' => self::t('Short description (shown on cards)', 300, true), 'hero_heading' => self::t('Page heading (optional)', 200), 'intro' => self::a('Introduction', 1500), 'description' => self::a('Description', 6000, false, 'Paragraphs, lists, **bold**, [links](https://…)'),
                    'deliverables' => self::a('Deliverables (one per line)', 3000, false, '', 'lines'), 'use_cases' => self::a('Suitable for (one per line)', 3000, false, '', 'lines'),
                    'faqs' => self::a('Questions and answers (one per line: Question | Answer)', 6000, false, '', 'lines'), 'related' => ['label' => 'Related services', 'kind' => 'multi', 'options' => 'services'],
                    'cover' => ['label' => 'Picture (optional)', 'kind' => 'media', 'col' => 'cover_media_id'], 'gallery' => ['label' => 'Gallery (optional)', 'kind' => 'gallery', 'col' => 'gallery_json'],
                    'show_price' => ['label' => 'Show a starting price on the page', 'kind' => 'bool'], 'starting_price' => ['label' => 'Starting price (₹)', 'kind' => 'money', 'col' => 'starting_price_minor', 'help' => 'Only shown when the box above is ticked. Never promise a price you have not agreed.'],
                ] + $seo],
            'team' => ['label' => 'Team', 'singular' => 'team member', 'table' => 'site_team', 'title' => 'full_name', 'slug' => false, 'statuses' => ['draft', 'published', 'archived'], 'public' => '/our-team', 'edit' => 'cms.edit', 'publish' => 'cms.publish',
                'help' => 'Only add real people, with their agreement. A published profile appears on the Our Team page.',
                'fields' => [
                    'full_name' => self::t('Full name', 120, true), 'designation' => self::t('Designation', 120, true),
                    'department' => ['label' => 'Group', 'kind' => 'select', 'options' => ['leadership' => 'Leadership', 'creative' => 'Creative Team', 'development' => 'Development Team', 'operations' => 'Operations and Support'], 'required' => true],
                    'photo' => ['label' => 'Photo', 'kind' => 'media', 'col' => 'photo_media_id'], 'bio' => self::a('Short biography', 1500), 'skills' => self::t('Skills (comma-separated)', 400),
                    'linkedin_url' => ['label' => 'LinkedIn address', 'kind' => 'url'], 'x_url' => ['label' => 'X / Twitter address', 'kind' => 'url'], 'website_url' => ['label' => 'Website address', 'kind' => 'url'],
                ]],
            'partners' => ['label' => 'Partners', 'singular' => 'partner', 'table' => 'site_partners', 'title' => 'name', 'slug' => false, 'statuses' => ['draft', 'published', 'archived'], 'public' => '/our-partners', 'edit' => 'cms.edit', 'publish' => 'cms.publish',
                'help' => 'Publish only organisations with a genuine relationship and their permission to show their name and logo. A submitted application never publishes anything by itself.',
                'fields' => [
                    'name' => self::t('Organisation name', 160, true), 'logo' => ['label' => 'Logo', 'kind' => 'media', 'col' => 'logo_media_id'], 'description' => self::t('Short description', 500),
                    'category' => self::t('Partnership category', 60), 'website_url' => ['label' => 'Website address', 'kind' => 'url'], 'contact_url' => ['label' => 'Public contact link', 'kind' => 'url'],
                ]],
            'portfolio' => ['label' => 'Portfolio', 'singular' => 'project', 'table' => 'site_portfolio', 'title' => 'title', 'slug' => true, 'statuses' => ['draft', 'published', 'archived'], 'public' => '/portfolio/', 'edit' => 'cms.edit', 'publish' => 'cms.publish',
                'help' => 'Only real projects you are authorised to show. Never use confidential client files.',
                'fields' => [
                    'title' => self::t('Project name', 160, true), 'category' => self::t('Category', 60), 'summary' => self::t('Short description', 400, true), 'services' => self::t('Services provided (comma-separated)', 400),
                    'cover' => ['label' => 'Cover picture', 'kind' => 'media', 'col' => 'cover_media_id'], 'gallery' => ['label' => 'Gallery', 'kind' => 'gallery', 'col' => 'gallery_json'],
                    'case_study' => self::a('Case study (optional)', 8000, false, 'Paragraphs, ## headings, lists, [links](https://…)'),
                ] + $seo],
            'jobs' => ['label' => 'Openings', 'singular' => 'opening', 'table' => 'site_jobs', 'title' => 'title', 'slug' => true, 'statuses' => ['draft', 'published', 'closed', 'archived'], 'public' => '/careers/', 'edit' => 'cms.edit', 'publish' => 'cms.publish',
                'help' => 'Only publish openings that are genuinely available. Do not promise selection or payment.',
                'fields' => [
                    'title' => self::t('Job or assignment title', 160, true), 'department' => self::t('Department', 80), 'engagement_type' => ['label' => 'Engagement type', 'kind' => 'select', 'options' => ['freelance' => 'Freelance assignment', 'contract' => 'Contract', 'part_time' => 'Part-time', 'full_time' => 'Full-time', 'internship' => 'Internship', 'other' => 'Other']],
                    'skills' => self::a('Skills required (one per line)', 3000, false, '', 'lines'), 'responsibilities' => self::a('Responsibilities (one per line)', 3000, false, '', 'lines'), 'deliverables' => self::a('Expected deliverables (one per line)', 3000, false, '', 'lines'),
                    'deadline' => ['label' => 'Application deadline', 'kind' => 'date'], 'compensation' => self::t('Compensation (only if approved for publication)', 200), 'instructions' => self::a('How to apply', 2000),
                ]],
            'faqs' => ['label' => 'FAQs', 'singular' => 'question', 'table' => 'site_faqs', 'title' => 'question', 'slug' => false, 'statuses' => ['draft', 'published', 'archived'], 'public' => '/faq', 'edit' => 'cms.edit', 'publish' => 'cms.publish',
                'help' => 'Questions are grouped by category on the FAQ page, which visitors can search.',
                'fields' => ['category' => self::t('Category', 60, true), 'question' => self::t('Question', 300, true), 'answer' => self::a('Answer', 3000, true)]],
            'policies' => ['label' => 'Policies', 'singular' => 'policy', 'table' => 'site_policies', 'title' => 'title', 'slug' => false, 'statuses' => ['draft', 'published', 'archived'], 'public' => '/', 'edit' => 'legal.manage', 'publish' => 'legal.manage', 'noCreate' => true, 'noDelete' => true,
                'help' => 'Starting drafts are provided for review. They cannot be published while a [ADMIN TO COMPLETE] note is left in the text. Every saved change is kept as a version.',
                'fields' => ['title' => self::t('Title', 160, true), 'effective_date' => ['label' => 'Effective date', 'kind' => 'date'], 'body' => self::a('Policy text', 40000, true, 'Paragraphs, ## headings, lists, **bold**, [links](https://…)')]],
        ];
    }

    /** @return array<string,mixed> */
    public static function def(string $entity): array
    {
        return self::defs()[$entity] ?? throw new \Vertex\Core\HttpException(404);
    }

    /** Public address of one policy. */
    public static function policyPath(string $slug): string
    {
        return '/' . $slug;
    }
}
