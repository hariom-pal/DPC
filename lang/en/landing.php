<?php

declare(strict_types=1);

/**
 * The public landing page (`welcome.blade.php`).
 *
 * Its own group rather than a corner of `ui.php`: this is the only screen a
 * visitor sees before authenticating, it is almost entirely prose, and none of
 * it is shared with any other view. Keeping it separate means the page can be
 * reworded without touching a file that every authenticated screen reads.
 *
 * The lists — quick actions, process stages, roles, notices, FAQ — are keyed
 * arrays rather than positional ones, so the Blade names what it is rendering
 * and a translator cannot silently shift a caption onto the wrong card.
 */

return [
    'months' => [
        'apr' => 'Apr',
        'aug' => 'Aug',
        'dec' => 'Dec',
        'feb' => 'Feb',
        'jan' => 'Jan',
        'jul' => 'Jul',
        'jun' => 'Jun',
        'mar' => 'Mar',
        'may' => 'May',
        'nov' => 'Nov',
        'oct' => 'Oct',
        'sep' => 'Sep',
    ],
    'title' => 'e-DPC Portal — Chhattisgarh Public Service Commission',
    'hero' => [
        'kicker' => 'Government of Chhattisgarh',
        'heading' => 'Online Departmental Promotion Committee',
        'subheading' => 'विभागीय पदोन्नति समिति — ऑनलाइन प्रक्रिया',
        'lead' => 'A single, paperless and auditable process for departmental promotions — from the department\'s proposal through the Commission\'s proceeding to the signed promotion list, with every decision recorded and verifiable.',
        'art_alt' => 'Illustration of a government secretariat building',
        'features' => [
            'secure' => 'Secure',
            'paperless' => 'Paperless',
            'audit' => 'Audit Trail',
            'signature' => 'Digital Signature',
            'workflow' => 'Workflow Driven',
            'roles' => 'Role Based',
        ],
    ],
    'sections' => [
        'get_started' => 'Get Started',
        'quick_actions' => 'Quick Actions',
        'about' => 'About',
        'about_heading' => 'What is a Departmental Promotion Committee?',
        'how_it_works' => 'How it works',
        'process_lead' => 'From registration to published result — ten stages.',
        'capabilities' => 'Capabilities',
        'system_features' => 'System Features',
        'who_uses_it' => 'Who uses it',
        'at_a_glance' => 'At a glance',
        'portal_statistics' => 'Portal Statistics',
        'statistics_note' => 'Live figures from this installation.',
        'questions' => 'Questions',
        'faq_heading' => 'Frequently Asked Questions',
    ],
    'quick_actions' => [
        'department' => [
            'title' => 'Department Login',
            'text' => 'Authorized Officers and Administrative Heads sign in here.',
        ],
        'administrator' => [
            'title' => 'CGPSC Administrator Login',
            'text' => 'Commission administrators — master data and approvals.',
        ],
        'self_inspection' => [
            'title' => 'Self-Inspection',
            'text' => 'Officers under consideration — view the data your department recorded.',
        ],
        'manual' => [
            'title' => 'User Manual',
            'text' => 'Step-by-step guidance for every role in the process.',
        ],
        'reports' => [
            'title' => 'Reports',
            'text' => 'Proceedings, promotion lists and signed documents.',
        ],
        'help_desk' => [
            'title' => 'Help Desk',
            'text' => 'Contact your nodal officer or the Commission.',
        ],
    ],
    'about' => [
        'lead_1' => 'A Departmental Promotion Committee (DPC) considers eligible officers for promotion against available vacancies. The department prepares the proposal — post, committee, working officers, vacancies, seniority list and the evaluation chart — and forwards it to the Commission. The Commission allots and schedules the sitting, conducts it, and approves the result.',
        'lead_2' => 'The portal conducts that entire process online. Marks are calculated by a fixed, versioned engine from the confidential reports on record; every correction is recorded beside the original rather than over it; and every legally significant action is digitally signed and permanently auditable.',
        'kinds' => [
            'fresh' => [
                'title' => 'Fresh DPC',
                'text' => 'A new promotion proposal, from the department to the Commission.',
            ],
            'review' => [
                'title' => 'Review DPC',
                'text' => 'Corrects an error found in a concluded DPC, without touching the original.',
            ],
            'circulation' => [
                'title' => 'Circulation DPC',
                'text' => 'Resolves a promotion the Commission reserved once the missing record arrives.',
            ],
            'publication' => [
                'title' => 'Publication',
                'text' => 'Signed proceedings and promotion lists released to the department.',
            ],
        ],
    ],
    'process' => [
        'registration' => [
            'title' => 'Registration',
            'text' => 'A department registers and verifies its officers.',
        ],
        'approval' => [
            'title' => 'CGPSC Administrator Approval',
            'text' => 'The Commission approves the department and issues credentials.',
        ],
        'login' => [
            'title' => 'Department Login',
            'text' => 'The Authorized Officer signs in to prepare the proposal.',
        ],
        'create' => [
            'title' => 'Create DPC',
            'text' => 'Post, committee, working officers, vacancies, seniority, evaluation chart.',
        ],
        'eligibility' => [
            'title' => 'Eligibility',
            'text' => 'Marks, gates, zone of consideration and Super Seat, by the versioned engine.',
        ],
        'proceeding' => [
            'title' => 'Proceeding',
            'text' => 'The Commission conducts the sitting, corrects the chart and calculates.',
        ],
        'review' => [
            'title' => 'Review DPC',
            'text' => 'An error found later is corrected by a Review that supersedes the original.',
        ],
        'circulation' => [
            'title' => 'Circulation',
            'text' => 'A reserved promotion is released once the missing record is supplied.',
        ],
        'publication' => [
            'title' => 'Publication',
            'text' => 'Signed proceedings and promotion lists are released to the department.',
        ],
        'reports' => [
            'title' => 'Reports',
            'text' => 'Documents, history and the audit trail remain available and verifiable.',
        ],
    ],
    'features' => [
        'workflow' => [
            'title' => 'Digital Workflow',
            'text' => 'Every stage moves through a defined workflow with a recorded actor, time and reason.',
        ],
        'audit' => [
            'title' => 'Audit Trail',
            'text' => 'A tamper-evident record of who did what, when — designed for scrutiny in CAT or the High Court.',
        ],
        'signature' => [
            'title' => 'Digital Signature',
            'text' => 'Legally significant actions are digitally signed; documents carry a verifiable content hash.',
        ],
        'review' => [
            'title' => 'Review DPC',
            'text' => 'A concluded DPC can be corrected without altering the original record.',
        ],
        'circulation' => [
            'title' => 'Circulation DPC',
            'text' => 'Reserved promotions are released on evidence, with the Commission\'s consent.',
        ],
        'security' => [
            'title' => 'Role Based Security',
            'text' => 'Every screen and action is gated by role, department and workflow state.',
        ],
        'transparency' => [
            'title' => 'Transparency',
            'text' => 'Results explain themselves — each officer\'s marks are traceable to the rules applied.',
        ],
        'notifications' => [
            'title' => 'Notifications',
            'text' => 'Departments and Commission members are told when something needs their action.',
        ],
        'reports' => [
            'title' => 'Reports',
            'text' => 'Proceedings, promotion lists and document history, downloadable and verifiable.',
        ],
    ],
    'roles' => [
        'purpose_label' => 'Purpose.',
        'responsibilities_label' => 'Responsibilities',
        'cards' => [
            'administrator' => [
                'title' => 'CGPSC Administrator',
                'purpose' => 'Runs the portal for the Commission.',
                'duties' => [
                    'Approves department registrations and issues credentials',
                    'Maintains master data — posts, categories, roster, rules',
                    'Manages users and sees integration status',
                ],
            ],
            'department' => [
                'title' => 'Authorized Officer & Administrative Head',
                'purpose' => 'Prepares and submits the department\'s proposal.',
                'duties' => [
                    'The Authorized Officer creates the DPC proposal and chart',
                    'The Administrative Head reviews and approves with a signature',
                    'Between them they raise Review and Circulation proposals',
                ],
            ],
            'chairman' => [
                'title' => 'Chairman',
                'purpose' => 'Accountable for the Commission\'s work on each DPC.',
                'duties' => [
                    'Allots a forwarded DPC to a Member, and may re-allot it',
                    'Oversees every proceeding',
                    'Conducts a sitting only after re-allotting it to himself',
                ],
            ],
            'member' => [
                'title' => 'Member',
                'purpose' => 'Conducts the sitting for the DPCs allotted to him.',
                'duties' => [
                    'Accepts the allotment and schedules the sitting',
                    'Corrects the chart, calculates and approves the result',
                    'Signs circulation notes releasing reserved promotions',
                ],
            ],
            'candidate' => [
                'title' => 'Promotion Candidate',
                'purpose' => 'Follows their own DPC and nothing else.',
                'duties' => [
                    'Sees the DPC they are being considered in, and its stage',
                    'Reads their own final result once it is published',
                    'Cannot see another officer, the committee or any marks',
                ],
            ],
            'commission' => [
                'title' => 'Commission',
                'purpose' => 'The institutional authority the process runs under.',
                'duties' => [
                    'Approves results by CGPSC',
                    'Consents to circulation of reserved promotions',
                    'Publishes signed proceedings and promotion lists',
                ],
            ],
        ],
    ],
    'statistics' => [
        'departments' => 'Departments',
        'users' => 'Users',
        'dpcs' => 'DPCs',
        'reviews' => 'Review DPCs',
        'circulations' => 'Circulations',
        'documents' => 'Documents',
    ],
    'notices' => [
        'heading' => 'Notice Board',
        'items' => [
            'circulation' => [
                'day' => '16',
                'month' => 'Aug',
                'title' => 'Circulation DPC is now available',
                'text' => 'Departments can resolve a reserved promotion online once the missing CR or immovable-property record is supplied.',
            ],
            'review' => [
                'day' => '14',
                'month' => 'Aug',
                'title' => 'Review DPC available to all departments',
                'text' => 'A concluded DPC can be corrected by a Review that references the original and states its grounds.',
            ],
            'signature' => [
                'day' => '13',
                'month' => 'Aug',
                'title' => 'Digital signature required at every approval',
                'text' => 'Approvals now carry an OTP step-up recorded in the audit trail as evidence of intent.',
            ],
            'nodal' => [
                'day' => '30',
                'month' => 'Jul',
                'title' => 'Departments to verify nodal officer details',
                'text' => 'Departments should confirm the mobile number and e-mail of their Authorized Officer.',
            ],
        ],
    ],
    'manual' => [
        'text' => 'Role-wise guidance covering registration, proposal preparation, the sitting, Review and Circulation. Issued by the Commission to each department\'s nodal officer.',
        'cta' => 'Request a copy',
    ],
    'contact' => [
        'organisation' => 'Chhattisgarh Public Service Commission',
        'address' => 'Shankar Nagar Road, Raipur, Chhattisgarh',
        'note' => 'Departments should raise queries through their own nodal officer, who is the Commission\'s point of contact.',
    ],
    'faq' => [
        'who' => [
            'q' => 'Who can register on this portal?',
            'a' => 'A government department that submits promotion proposals to the Commission. The department registers, and the Commission approves the registration and issues credentials to the Authorized Officer and the Administrative Head.',
        ],
        'marks' => [
            'q' => 'How are promotion marks calculated?',
            'a' => 'From the confidential reports on record, by a fixed engine using the published rules — grade marks, the tribal-area bonus, the qualifying score for the class pair, the disqualifying-grade gate and the last-year gate. The rules come from configuration and government notification, never from ad-hoc changes to the software.',
        ],
        'error' => [
            'q' => 'What happens if an error is found after a DPC has concluded?',
            'a' => 'The department raises a Review DPC, stating the grounds. The Review references the original, is decided under the rules that applied at the time, and supersedes the original when approved. The original record is never altered.',
        ],
        'reserved' => [
            'q' => 'Why is a promotion sometimes reserved?',
            'a' => 'If an officer\'s confidential reports are missing for two or more years, or the immovable-property return is not recorded, the officer takes part in the DPC but the promotion action is reserved. When the missing record is supplied, a Circulation note obtains the Commission\'s consent for the promotion to proceed.',
        ],
        'documents' => [
            'q' => 'Are the documents legally reliable?',
            'a' => 'Every generated document is stored unchanged, carries a SHA-256 content hash, and is digitally signed. A department\'s copy can be verified against the hash the Commission issued, and superseded documents remain available and are watermarked.',
        ],
        'audit' => [
            'q' => 'Is the process auditable?',
            'a' => 'Yes. Every workflow transition is recorded with the actor, the time and the reason, and the activity timeline can reconstruct the history of a DPC without reading a single business table.',
        ],
    ],
    'cta' => [
        'heading' => 'Ready to begin?',
        'lead' => 'Register your department, or sign in to continue an existing proposal.',
    ],
];
