<?php

declare(strict_types=1);

/** Phase 11 form builder: field types, reserved addresses and ready-made templates an administrator can start from. */
return [
    'types' => [
        'text' => 'Short text', 'textarea' => 'Long text', 'email' => 'Email address', 'phone' => 'Phone number', 'number' => 'Number', 'date' => 'Date',
        'select' => 'Drop-down list', 'radio' => 'Choose one (buttons)', 'checkbox' => 'Single tick box', 'multiselect' => 'Choose several (tick boxes)',
        'heading' => 'Section heading', 'info' => 'Explanatory text',
    ],
    'choice_types' => ['select', 'radio', 'multiselect'],
    'display_only' => ['heading', 'info'],
    'reserved_slugs' => ['admin', 'api', 'login', 'logout', 'register', 'new', 'edit', 'thanks', 'assets', 'static'],
    'access' => [
        'public' => 'Anyone with the link',
        'private_link' => 'Only people who have the private link (with its secret key)',
        'login' => 'Signed-in users only',
        'client' => 'Signed-in clients only',
    ],
    'enquiry_fields' => ['contact_name' => 'Contact name', 'email' => 'Email', 'phone' => 'Phone', 'company_name' => 'Company', 'description' => 'Message / description', 'service' => 'Service'],
    'templates' => [
        'contact' => ['title' => 'Contact us', 'description' => 'A simple enquiry form that creates a CRM lead.', 'create_enquiry' => true, 'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'maps_to' => 'contact_name'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'maps_to' => 'email'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false, 'maps_to' => 'phone'],
            ['key' => 'message', 'label' => 'How can we help?', 'type' => 'textarea', 'required' => true, 'maps_to' => 'description'],
        ]],
        'feedback' => ['title' => 'Client feedback', 'description' => 'Rating and comments after a project.', 'create_enquiry' => false, 'fields' => [
            ['key' => 'rating', 'label' => 'How happy are you with the work?', 'type' => 'radio', 'required' => true, 'options' => ['5 - Excellent', '4 - Good', '3 - Fine', '2 - Poor', '1 - Very poor']],
            ['key' => 'improve', 'label' => 'What should we improve?', 'type' => 'textarea', 'required' => false, 'show_if' => ['rating', 'not_equals', '5 - Excellent']],
            ['key' => 'recommend', 'label' => 'I would recommend Vertex to others', 'type' => 'checkbox', 'required' => false],
        ]],
        'requirements' => ['title' => 'Project requirements', 'description' => 'Collects what a new website or app project needs.', 'create_enquiry' => true, 'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'maps_to' => 'contact_name'],
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'maps_to' => 'email'],
            ['key' => 'company', 'label' => 'Business name', 'type' => 'text', 'required' => false, 'maps_to' => 'company_name'],
            ['key' => 'kind', 'label' => 'What do you need?', 'type' => 'select', 'required' => true, 'options' => ['New website', 'Website redesign', 'Mobile app', 'Video editing', 'Other']],
            ['key' => 'pages', 'label' => 'About how many pages?', 'type' => 'number', 'required' => false, 'min' => 1, 'max' => 500, 'show_if' => ['kind', 'contains', 'website']],
            ['key' => 'details', 'label' => 'Tell us about the project', 'type' => 'textarea', 'required' => true, 'maps_to' => 'description'],
        ]],
    ],
];
