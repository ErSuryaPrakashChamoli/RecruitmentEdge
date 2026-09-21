<?php

namespace Database\Seeders\Demo;

/**
 * The fixed cast and script of the demo story: offices, departments, people (with their demo
 * logins), requisitions and the text the demo shows. Everything else — candidates, their journeys,
 * activity volumes — is generated from these with a seeded random generator.
 */
final class DemoCatalog
{
    /**
     * `HO` already exists (OrganizationSeeder) and doubles as the Bengaluru office.
     *
     * @var array<string, array{name: string, city: string, state: string}>
     */
    public const array LOCATIONS = [
        'HO' => ['name' => 'Head Office', 'city' => 'Bengaluru', 'state' => 'Karnataka'],
        'MUM' => ['name' => 'Mumbai Office', 'city' => 'Mumbai', 'state' => 'Maharashtra'],
        'DEL' => ['name' => 'Gurugram Office', 'city' => 'Gurugram', 'state' => 'Haryana'],
        'PUN' => ['name' => 'Pune Office', 'city' => 'Pune', 'state' => 'Maharashtra'],
        'HYD' => ['name' => 'Hyderabad Office', 'city' => 'Hyderabad', 'state' => 'Telangana'],
        'CHN' => ['name' => 'Chennai Office', 'city' => 'Chennai', 'state' => 'Tamil Nadu'],
        'KOL' => ['name' => 'Kolkata Office', 'city' => 'Kolkata', 'state' => 'West Bengal'],
        'AMD' => ['name' => 'Ahmedabad Office', 'city' => 'Ahmedabad', 'state' => 'Gujarat'],
    ];

    /**
     * `TA` already exists (OrganizationSeeder).
     *
     * @var array<string, string>
     */
    public const array DEPARTMENTS = [
        'TA' => 'Talent Acquisition',
        'MGMT' => 'Leadership',
        'SAL' => 'Sales',
        'OPS' => 'Operations',
        'CS' => 'Customer Support',
        'TECH' => 'Technology',
        'FIN' => 'Finance & Accounts',
        'MKT' => 'Marketing',
        'RTL' => 'Retail',
        'HR' => 'Human Resources',
    ];

    /**
     * The five Talent Acquisition designations already exist (OrganizationSeeder).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array DESIGNATIONS = [
        'DSG-CEO' => ['Chief Executive Officer', 'MGMT'],
        'DSG-SALES-HEAD' => ['Head of Sales', 'SAL'],
        'DSG-RSH' => ['Regional Sales Head', 'SAL'],
        'DSG-BDM' => ['Business Development Manager', 'SAL'],
        'DSG-ASM' => ['Area Sales Manager', 'SAL'],
        'DSG-SALES-TL' => ['Sales Team Leader', 'SAL'],
        'DSG-SE' => ['Sales Executive', 'SAL'],
        'DSG-FSO' => ['Field Sales Officer', 'SAL'],
        'DSG-TC' => ['Telecaller', 'SAL'],
        'DSG-OPS-HEAD' => ['Head of Operations', 'OPS'],
        'DSG-WHS' => ['Warehouse Supervisor', 'OPS'],
        'DSG-OE' => ['Operations Executive', 'OPS'],
        'DSG-DA' => ['Delivery Associate', 'OPS'],
        'DSG-CS-MGR' => ['Customer Support Manager', 'CS'],
        'DSG-CSM' => ['Customer Success Manager', 'CS'],
        'DSG-CS-TL' => ['Team Leader - Customer Support', 'CS'],
        'DSG-CSA' => ['Customer Support Associate', 'CS'],
        'DSG-ENG-MGR' => ['Engineering Manager', 'TECH'],
        'DSG-PM' => ['Product Manager', 'TECH'],
        'DSG-TECH-LEAD' => ['Tech Lead', 'TECH'],
        'DSG-SLD' => ['Senior Laravel Developer', 'TECH'],
        'DSG-RFD' => ['React Frontend Developer', 'TECH'],
        'DSG-QA' => ['QA Engineer', 'TECH'],
        'DSG-DAN' => ['Data Analyst', 'TECH'],
        'DSG-FC' => ['Finance Controller', 'FIN'],
        'DSG-ACC' => ['Accountant', 'FIN'],
        'DSG-MKT-MGR' => ['Marketing Manager', 'MKT'],
        'DSG-DME' => ['Digital Marketing Executive', 'MKT'],
        'DSG-GD' => ['Graphic Designer', 'MKT'],
        'DSG-RTL-HEAD' => ['Retail Operations Head', 'RTL'],
        'DSG-SM' => ['Store Manager', 'RTL'],
        'DSG-RSA' => ['Retail Sales Associate', 'RTL'],
        'DSG-HRBP' => ['HR Business Partner', 'HR'],
        'DSG-HRE' => ['HR Executive', 'HR'],
    ];

    /**
     * Ordered so every manager is created before their reports (the hierarchy closure table is
     * built as each employee is created). `login` is the account's email when the person can sign
     * in; `role` is their Spatie role.
     *
     * @var array<string, array{first: string, last: string, designation: string, department: string, location: string, reports_to: string|null, role?: string, login?: string, level: string, category: string, years: int}>
     */
    public const array PEOPLE = [
        'ceo' => ['first' => 'Vivek', 'last' => 'Chandra', 'designation' => 'DSG-CEO', 'department' => 'MGMT', 'location' => 'HO', 'reports_to' => null, 'level' => 'L1', 'category' => 'Leadership', 'years' => 9],
        'chro' => ['first' => 'Ananya', 'last' => 'Iyer', 'designation' => 'DSG-CHRO', 'department' => 'TA', 'location' => 'HO', 'reports_to' => 'ceo', 'role' => 'chro', 'login' => 'chro@example.com', 'level' => 'L1', 'category' => 'Leadership', 'years' => 7],
        'vp_hr' => ['first' => 'Rajesh', 'last' => 'Menon', 'designation' => 'DSG-VPHR', 'department' => 'TA', 'location' => 'HO', 'reports_to' => 'chro', 'role' => 'vp_hr', 'login' => 'vphr@example.com', 'level' => 'L2', 'category' => 'Leadership', 'years' => 6],
        'mgr_west' => ['first' => 'Neha', 'last' => 'Kapoor', 'designation' => 'DSG-MGR', 'department' => 'TA', 'location' => 'MUM', 'reports_to' => 'vp_hr', 'role' => 'manager', 'login' => 'manager@example.com', 'level' => 'L3', 'category' => 'Talent Acquisition', 'years' => 5],
        'mgr_south' => ['first' => 'Sandeep', 'last' => 'Rao', 'designation' => 'DSG-MGR', 'department' => 'TA', 'location' => 'HO', 'reports_to' => 'vp_hr', 'role' => 'manager', 'login' => 'sandeep.rao@example.com', 'level' => 'L3', 'category' => 'Talent Acquisition', 'years' => 4],
        'am_west' => ['first' => 'Vikram', 'last' => 'Singh', 'designation' => 'DSG-AM', 'department' => 'TA', 'location' => 'MUM', 'reports_to' => 'mgr_west', 'role' => 'assistant_manager', 'login' => 'am@example.com', 'level' => 'L4', 'category' => 'Talent Acquisition', 'years' => 4],
        'am_north' => ['first' => 'Kavya', 'last' => 'Reddy', 'designation' => 'DSG-AM', 'department' => 'TA', 'location' => 'DEL', 'reports_to' => 'mgr_west', 'role' => 'assistant_manager', 'login' => 'kavya.reddy@example.com', 'level' => 'L4', 'category' => 'Talent Acquisition', 'years' => 3],
        'am_south' => ['first' => 'Meera', 'last' => 'Pillai', 'designation' => 'DSG-AM', 'department' => 'TA', 'location' => 'HO', 'reports_to' => 'mgr_south', 'role' => 'assistant_manager', 'login' => 'meera.pillai@example.com', 'level' => 'L4', 'category' => 'Talent Acquisition', 'years' => 3],
        'r_priya' => ['first' => 'Priya', 'last' => 'Nair', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'MUM', 'reports_to' => 'am_west', 'role' => 'recruiter', 'login' => 'recruiter@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 3],
        'r_arjun' => ['first' => 'Arjun', 'last' => 'Mehta', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'MUM', 'reports_to' => 'am_west', 'role' => 'recruiter', 'login' => 'arjun.mehta@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 2],
        'r_sneha' => ['first' => 'Sneha', 'last' => 'Kulkarni', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'PUN', 'reports_to' => 'am_west', 'role' => 'recruiter', 'login' => 'sneha.kulkarni@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 2],
        'r_rohit' => ['first' => 'Rohit', 'last' => 'Verma', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'DEL', 'reports_to' => 'am_north', 'role' => 'recruiter', 'login' => 'rohit.verma@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 2],
        'r_pooja' => ['first' => 'Pooja', 'last' => 'Desai', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'AMD', 'reports_to' => 'am_north', 'role' => 'recruiter', 'login' => 'pooja.desai@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 1],
        'r_imran' => ['first' => 'Imran', 'last' => 'Shaikh', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'KOL', 'reports_to' => 'am_north', 'role' => 'recruiter', 'login' => 'imran.shaikh@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 1],
        'r_karthik' => ['first' => 'Karthik', 'last' => 'Subramanian', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'CHN', 'reports_to' => 'am_south', 'role' => 'recruiter', 'login' => 'karthik.subramanian@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 3],
        'r_divya' => ['first' => 'Divya', 'last' => 'Joshi', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'HO', 'reports_to' => 'am_south', 'role' => 'recruiter', 'login' => 'divya.joshi@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 2],
        'r_aman' => ['first' => 'Aman', 'last' => 'Gupta', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'HYD', 'reports_to' => 'am_south', 'role' => 'recruiter', 'login' => 'aman.gupta@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 1],
        'r_lakshmi' => ['first' => 'Lakshmi', 'last' => 'Narayanan', 'designation' => 'DSG-RCT', 'department' => 'TA', 'location' => 'HO', 'reports_to' => 'am_south', 'role' => 'recruiter', 'login' => 'lakshmi.narayanan@example.com', 'level' => 'L5', 'category' => 'Talent Acquisition', 'years' => 2],
        'hrbp' => ['first' => 'Manoj', 'last' => 'Tiwari', 'designation' => 'DSG-HRBP', 'department' => 'HR', 'location' => 'HO', 'reports_to' => 'chro', 'level' => 'L3', 'category' => 'Human Resources', 'years' => 5],
        'sales_head' => ['first' => 'Amit', 'last' => 'Khanna', 'designation' => 'DSG-SALES-HEAD', 'department' => 'SAL', 'location' => 'MUM', 'reports_to' => 'ceo', 'level' => 'L2', 'category' => 'Leadership', 'years' => 8],
        'sales_tl' => ['first' => 'Varun', 'last' => 'Malhotra', 'designation' => 'DSG-SALES-TL', 'department' => 'SAL', 'location' => 'DEL', 'reports_to' => 'sales_head', 'level' => 'L4', 'category' => 'Sales', 'years' => 4],
        'ops_head' => ['first' => 'Farhan', 'last' => 'Qureshi', 'designation' => 'DSG-OPS-HEAD', 'department' => 'OPS', 'location' => 'DEL', 'reports_to' => 'ceo', 'level' => 'L2', 'category' => 'Leadership', 'years' => 6],
        'cs_head' => ['first' => 'Deepa', 'last' => 'Krishnan', 'designation' => 'DSG-CS-MGR', 'department' => 'CS', 'location' => 'HO', 'reports_to' => 'ceo', 'level' => 'L3', 'category' => 'Customer Support', 'years' => 5],
        'cs_tl' => ['first' => 'Suresh', 'last' => 'Babu', 'designation' => 'DSG-CS-TL', 'department' => 'CS', 'location' => 'CHN', 'reports_to' => 'cs_head', 'level' => 'L4', 'category' => 'Customer Support', 'years' => 3],
        'tech_head' => ['first' => 'Nikhil', 'last' => 'Bansal', 'designation' => 'DSG-ENG-MGR', 'department' => 'TECH', 'location' => 'HO', 'reports_to' => 'ceo', 'level' => 'L2', 'category' => 'Technology', 'years' => 6],
        'tech_lead' => ['first' => 'Ritu', 'last' => 'Sharma', 'designation' => 'DSG-TECH-LEAD', 'department' => 'TECH', 'location' => 'PUN', 'reports_to' => 'tech_head', 'level' => 'L3', 'category' => 'Technology', 'years' => 4],
        'retail_head' => ['first' => 'Rakesh', 'last' => 'Patel', 'designation' => 'DSG-RTL-HEAD', 'department' => 'RTL', 'location' => 'AMD', 'reports_to' => 'ceo', 'level' => 'L2', 'category' => 'Leadership', 'years' => 7],
        'fin_head' => ['first' => 'Shalini', 'last' => 'Agarwal', 'designation' => 'DSG-FC', 'department' => 'FIN', 'location' => 'MUM', 'reports_to' => 'ceo', 'level' => 'L2', 'category' => 'Finance', 'years' => 6],
        'mkt_head' => ['first' => 'Tanvi', 'last' => 'Shah', 'designation' => 'DSG-MKT-MGR', 'department' => 'MKT', 'location' => 'DEL', 'reports_to' => 'ceo', 'level' => 'L3', 'category' => 'Marketing', 'years' => 4],
    ];

    /**
     * People on the interviewer list (Administration → Interviewers).
     *
     * @var array<int, string>
     */
    public const array INTERVIEWERS = [
        'ceo', 'hrbp', 'mgr_west', 'mgr_south', 'am_west', 'am_north', 'am_south', 'sales_head', 'sales_tl',
        'ops_head', 'cs_head', 'cs_tl', 'tech_head', 'tech_lead', 'retail_head', 'fin_head', 'mkt_head',
    ];

    /**
     * The demo logins shown on the login page, in presentation order.
     *
     * @var array<int, array{role: string, person: string, summary: string}>
     */
    public const array LOGINS = [
        ['role' => 'CHRO', 'person' => 'chro', 'summary' => 'Sees everything: every team, report, setting and approval.'],
        ['role' => 'VP HR', 'person' => 'vp_hr', 'summary' => 'Approves requisitions and incentives; sees the whole TA team.'],
        ['role' => 'Recruitment Manager', 'person' => 'mgr_west', 'summary' => 'Runs the West & North team; releases offers.'],
        ['role' => 'Assistant Manager', 'person' => 'am_west', 'summary' => 'Leads 3 recruiters in Mumbai & Pune.'],
        ['role' => 'Recruiter', 'person' => 'r_priya', 'summary' => 'Works her own pipeline: calls, interviews, offers.'],
    ];

    /**
     * Requisitions with their story. `opened` is days before today; `apps` is applications per
     * opening at scale 1; `rounds` is [round name|null, interviewer, mode] per interview round.
     * `plan` is one of open | on_hold | closed | approved | pending_approval | draft | cancelled,
     * with `plan_days` = days before today that the plan's final move happens.
     *
     * @var array<string, array<string, mixed>>
     */
    public const array REQUISITIONS = [
        'se_mum' => [
            'designation' => 'DSG-SE', 'department' => 'SAL', 'location' => 'MUM', 'openings' => 20, 'type' => 'permanent',
            'salary' => [240000, 360000], 'experience' => [0, 2], 'qualification' => 'Graduate (any stream)',
            'skills' => ['B2B Sales', 'Cold Calling', 'Negotiation', 'CRM', 'Communication'], 'shift' => 'Day shift',
            'priority' => 'urgent', 'opened' => 160, 'plan' => 'open', 'recruiters' => ['r_priya', 'r_arjun'],
            'hiring_manager' => 'sales_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 8,
            'rounds' => [['HR Round', 'am_west', 'phone'], ['Sales Round', 'sales_head', 'in_person']],
        ],
        'se_del' => [
            'designation' => 'DSG-SE', 'department' => 'SAL', 'location' => 'DEL', 'openings' => 15, 'type' => 'permanent',
            'salary' => [240000, 340000], 'experience' => [0, 2], 'qualification' => 'Graduate (any stream)',
            'skills' => ['B2B Sales', 'Cold Calling', 'Hindi', 'Lead Generation', 'Communication'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 125, 'plan' => 'open', 'recruiters' => ['r_rohit'],
            'hiring_manager' => 'sales_tl', 'assistant_manager' => 'am_north', 'manager' => 'mgr_west', 'apps' => 8,
            'rounds' => [['HR Round', 'am_north', 'phone'], ['Sales Round', 'sales_tl', 'in_person']],
        ],
        'fso_pun' => [
            'designation' => 'DSG-FSO', 'department' => 'SAL', 'location' => 'PUN', 'openings' => 12, 'type' => 'permanent',
            'salary' => [220000, 300000], 'experience' => [0, 3], 'qualification' => '12th pass / Graduate',
            'skills' => ['Field Sales', 'Two-wheeler', 'Local Market Knowledge', 'Lead Generation'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 105, 'plan' => 'open', 'recruiters' => ['r_sneha'],
            'hiring_manager' => 'sales_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 8,
            'rounds' => [['HR Round', 'am_west', 'phone'], ['Sales Round', 'sales_head', 'in_person']],
        ],
        'asm_hyd' => [
            'designation' => 'DSG-ASM', 'department' => 'SAL', 'location' => 'HYD', 'openings' => 2, 'type' => 'permanent',
            'salary' => [800000, 1200000], 'experience' => [5, 9], 'qualification' => 'MBA (Sales & Marketing)',
            'skills' => ['Team Management', 'Channel Sales', 'Distribution', 'P&L Ownership'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 95, 'plan' => 'open', 'recruiters' => ['r_aman'],
            'hiring_manager' => 'sales_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 13,
            'rounds' => [['HR Round', 'am_south', 'video_call'], ['Sales Round', 'sales_head', 'video_call'], ['Final Round', 'ceo', 'in_person']],
        ],
        'csa_blr' => [
            'designation' => 'DSG-CSA', 'department' => 'CS', 'location' => 'HO', 'openings' => 25, 'type' => 'permanent',
            'salary' => [220000, 300000], 'experience' => [0, 2], 'qualification' => 'Graduate (any stream)',
            'skills' => ['English Communication', 'Customer Handling', 'CRM', 'Hindi', 'Kannada'], 'shift' => 'Rotational shifts',
            'priority' => 'urgent', 'opened' => 165, 'plan' => 'open', 'recruiters' => ['r_divya', 'r_lakshmi', 'r_aman'],
            'hiring_manager' => 'cs_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 7,
            'rounds' => [['HR Round', 'am_south', 'phone'], ['Operations Round', 'cs_head', 'in_person']],
        ],
        'csa_chn' => [
            'designation' => 'DSG-CSA', 'department' => 'CS', 'location' => 'CHN', 'openings' => 12, 'type' => 'permanent',
            'salary' => [200000, 270000], 'experience' => [0, 2], 'qualification' => 'Graduate (any stream)',
            'skills' => ['Email Support', 'Chat Support', 'Typing Speed', 'MS Excel', 'Tamil'], 'shift' => 'Night shift',
            'priority' => 'medium', 'opened' => 115, 'plan' => 'open', 'recruiters' => ['r_karthik'],
            'hiring_manager' => 'cs_tl', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 8,
            'rounds' => [['HR Round', 'am_south', 'phone'], ['Operations Round', 'cs_tl', 'in_person']],
        ],
        'cstl_blr' => [
            'designation' => 'DSG-CS-TL', 'department' => 'CS', 'location' => 'HO', 'openings' => 2, 'type' => 'permanent',
            'salary' => [450000, 600000], 'experience' => [3, 6], 'qualification' => 'Graduate (any stream)',
            'skills' => ['Team Handling', 'Quality Monitoring', 'Escalation Management', 'WFM'], 'shift' => 'Rotational shifts',
            'priority' => 'medium', 'opened' => 85, 'plan' => 'open', 'recruiters' => ['r_lakshmi'],
            'hiring_manager' => 'cs_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 14,
            'rounds' => [['HR Round', 'am_south', 'phone'], ['Operations Round', 'cs_head', 'in_person'], ['Final Round', 'hrbp', 'in_person']],
        ],
        'tc_kol' => [
            'designation' => 'DSG-TC', 'department' => 'SAL', 'location' => 'KOL', 'openings' => 20, 'type' => 'contract',
            'salary' => [180000, 240000], 'experience' => [0, 1], 'qualification' => '12th pass',
            'skills' => ['Outbound Calling', 'Bengali', 'Hindi', 'Lead Qualification'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 135, 'plan' => 'open', 'recruiters' => ['r_imran'],
            'hiring_manager' => 'sales_tl', 'assistant_manager' => 'am_north', 'manager' => 'mgr_west', 'apps' => 7,
            'rounds' => [['HR Round', 'am_north', 'phone'], ['Sales Round', 'sales_tl', 'video_call']],
        ],
        'sld_blr' => [
            'designation' => 'DSG-SLD', 'department' => 'TECH', 'location' => 'HO', 'openings' => 3, 'type' => 'permanent',
            'salary' => [1200000, 2000000], 'experience' => [4, 8], 'qualification' => 'B.Tech / MCA',
            'skills' => ['PHP', 'Laravel', 'MySQL', 'REST APIs', 'Redis', 'AWS'], 'shift' => 'Day shift (hybrid)',
            'priority' => 'urgent', 'opened' => 145, 'plan' => 'open', 'recruiters' => ['r_divya'],
            'hiring_manager' => 'tech_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 12,
            'rounds' => [['HR Round', 'am_south', 'video_call'], [null, 'tech_lead', 'video_call'], ['Final Round', 'tech_head', 'in_person']],
        ],
        'rfd_pun' => [
            'designation' => 'DSG-RFD', 'department' => 'TECH', 'location' => 'PUN', 'openings' => 2, 'type' => 'permanent',
            'salary' => [800000, 1400000], 'experience' => [2, 5], 'qualification' => 'B.Tech / BCA',
            'skills' => ['React', 'TypeScript', 'Tailwind CSS', 'REST APIs', 'Git'], 'shift' => 'Day shift (hybrid)',
            'priority' => 'high', 'opened' => 100, 'plan' => 'open', 'recruiters' => ['r_sneha'],
            'hiring_manager' => 'tech_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 12,
            'rounds' => [['HR Round', 'am_west', 'video_call'], [null, 'tech_lead', 'video_call'], ['Final Round', 'tech_head', 'video_call']],
        ],
        'qa_blr' => [
            'designation' => 'DSG-QA', 'department' => 'TECH', 'location' => 'HO', 'openings' => 2, 'type' => 'permanent',
            'salary' => [600000, 1000000], 'experience' => [2, 5], 'qualification' => 'B.Tech / B.Sc (Computer Science)',
            'skills' => ['Manual Testing', 'Selenium', 'API Testing', 'Jira'], 'shift' => 'Day shift',
            'priority' => 'medium', 'opened' => 75, 'plan' => 'open', 'recruiters' => ['r_lakshmi'],
            'hiring_manager' => 'tech_lead', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 11,
            'rounds' => [['HR Round', 'am_south', 'video_call'], [null, 'tech_lead', 'video_call']],
        ],
        'dan_hyd' => [
            'designation' => 'DSG-DAN', 'department' => 'TECH', 'location' => 'HYD', 'openings' => 1, 'type' => 'permanent',
            'salary' => [600000, 900000], 'experience' => [1, 4], 'qualification' => 'B.Tech / B.Sc (Statistics)',
            'skills' => ['SQL', 'Python', 'Power BI', 'Excel'], 'shift' => 'Day shift',
            'priority' => 'medium', 'opened' => 150, 'plan' => 'closed', 'plan_days' => 25, 'recruiters' => ['r_aman'],
            'hiring_manager' => 'tech_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 18,
            'rounds' => [['HR Round', 'am_south', 'video_call'], [null, 'tech_lead', 'video_call'], ['Final Round', 'tech_head', 'video_call']],
        ],
        'sm_amd' => [
            'designation' => 'DSG-SM', 'department' => 'RTL', 'location' => 'AMD', 'openings' => 3, 'type' => 'permanent',
            'salary' => [450000, 650000], 'experience' => [4, 8], 'qualification' => 'Graduate (any stream)',
            'skills' => ['Store Operations', 'Inventory Control', 'Team Management', 'Visual Merchandising', 'Gujarati'], 'shift' => 'Store hours',
            'priority' => 'high', 'opened' => 95, 'plan' => 'open', 'recruiters' => ['r_pooja'],
            'hiring_manager' => 'retail_head', 'assistant_manager' => 'am_north', 'manager' => 'mgr_west', 'apps' => 11,
            'rounds' => [['HR Round', 'am_north', 'phone'], ['Operations Round', 'retail_head', 'in_person']],
        ],
        'rsa_mum' => [
            'designation' => 'DSG-RSA', 'department' => 'RTL', 'location' => 'MUM', 'openings' => 20, 'type' => 'temporary',
            'salary' => [180000, 240000], 'experience' => [0, 2], 'qualification' => '12th pass',
            'skills' => ['Customer Service', 'Billing', 'Product Knowledge', 'Marathi'], 'shift' => 'Store shifts',
            'priority' => 'medium', 'opened' => 70, 'plan' => 'open', 'recruiters' => ['r_priya', 'r_arjun', 'r_pooja'],
            'hiring_manager' => 'retail_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 7,
            'rounds' => [['Operations Round', 'retail_head', 'in_person']],
        ],
        'whs_del' => [
            'designation' => 'DSG-WHS', 'department' => 'OPS', 'location' => 'DEL', 'openings' => 2, 'type' => 'permanent',
            'salary' => [350000, 480000], 'experience' => [3, 6], 'qualification' => 'Graduate (any stream)',
            'skills' => ['Inventory Management', 'WMS', 'Team Handling', 'Dispatch Planning'], 'shift' => 'Rotational shifts',
            'priority' => 'medium', 'opened' => 120, 'plan' => 'on_hold', 'plan_days' => 14, 'recruiters' => ['r_rohit'],
            'hiring_manager' => 'ops_head', 'assistant_manager' => 'am_north', 'manager' => 'mgr_west', 'apps' => 8,
            'rounds' => [['HR Round', 'am_north', 'phone'], ['Operations Round', 'ops_head', 'in_person']],
        ],
        'da_blr' => [
            'designation' => 'DSG-DA', 'department' => 'OPS', 'location' => 'HO', 'openings' => 30, 'type' => 'contract',
            'salary' => [180000, 260000], 'experience' => [0, 2], 'qualification' => '10th pass',
            'skills' => ['Two-wheeler', 'Driving Licence', 'Local Route Knowledge', 'Smartphone'], 'shift' => 'Day shift',
            'priority' => 'urgent', 'opened' => 60, 'plan' => 'open', 'recruiters' => ['r_lakshmi', 'r_karthik'],
            'hiring_manager' => 'ops_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 6,
            'rounds' => [['Operations Round', 'ops_head', 'in_person']],
        ],
        'acc_mum' => [
            'designation' => 'DSG-ACC', 'department' => 'FIN', 'location' => 'MUM', 'openings' => 1, 'type' => 'permanent',
            'salary' => [400000, 550000], 'experience' => [2, 5], 'qualification' => 'B.Com / M.Com',
            'skills' => ['Tally', 'GST', 'TDS', 'Bank Reconciliation', 'MS Excel'], 'shift' => 'Day shift',
            'priority' => 'medium', 'opened' => 170, 'plan' => 'closed', 'plan_days' => 40, 'recruiters' => ['r_arjun'],
            'hiring_manager' => 'fin_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 12,
            'rounds' => [['HR Round', 'am_west', 'phone'], ['Final Round', 'fin_head', 'in_person']],
        ],
        'hre_blr' => [
            'designation' => 'DSG-HRE', 'department' => 'HR', 'location' => 'HO', 'openings' => 1, 'type' => 'permanent',
            'salary' => [350000, 450000], 'experience' => [1, 3], 'qualification' => 'MBA (HR)',
            'skills' => ['Onboarding', 'HRMS', 'Employee Engagement', 'Payroll Coordination'], 'shift' => 'Day shift',
            'priority' => 'low', 'opened' => 85, 'plan' => 'open', 'recruiters' => ['r_lakshmi'],
            'hiring_manager' => 'hrbp', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 14,
            'rounds' => [['HR Round', 'am_south', 'phone'], ['Final Round', 'hrbp', 'in_person']],
        ],
        'dme_del' => [
            'designation' => 'DSG-DME', 'department' => 'MKT', 'location' => 'DEL', 'openings' => 2, 'type' => 'permanent',
            'salary' => [350000, 550000], 'experience' => [1, 3], 'qualification' => 'Graduate / MBA (Marketing)',
            'skills' => ['SEO', 'Google Ads', 'Meta Ads', 'Content Marketing', 'Google Analytics'], 'shift' => 'Day shift',
            'priority' => 'medium', 'opened' => 65, 'plan' => 'open', 'recruiters' => ['r_rohit'],
            'hiring_manager' => 'mkt_head', 'assistant_manager' => 'am_north', 'manager' => 'mgr_west', 'apps' => 14,
            'rounds' => [['HR Round', 'am_north', 'video_call'], ['Final Round', 'mkt_head', 'in_person']],
        ],
        'bdm_mum' => [
            'designation' => 'DSG-BDM', 'department' => 'SAL', 'location' => 'MUM', 'openings' => 2, 'type' => 'permanent',
            'salary' => [900000, 1400000], 'experience' => [5, 10], 'qualification' => 'MBA',
            'skills' => ['Enterprise Sales', 'Key Account Management', 'Negotiation', 'Market Expansion'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 48, 'plan' => 'open', 'recruiters' => ['r_priya'],
            'hiring_manager' => 'sales_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 14,
            'rounds' => [['HR Round', 'am_west', 'phone'], ['Sales Round', 'sales_head', 'in_person'], ['Final Round', 'ceo', 'in_person']],
        ],
        'oe_chn' => [
            'designation' => 'DSG-OE', 'department' => 'OPS', 'location' => 'CHN', 'openings' => 3, 'type' => 'permanent',
            'salary' => [250000, 350000], 'experience' => [1, 3], 'qualification' => 'Graduate (any stream)',
            'skills' => ['MIS Reporting', 'Vendor Coordination', 'MS Excel', 'Process Adherence'], 'shift' => 'Day shift',
            'priority' => 'medium', 'opened' => 38, 'plan' => 'open', 'recruiters' => ['r_karthik'],
            'hiring_manager' => 'ops_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 8,
            'rounds' => [['HR Round', 'am_south', 'phone'], ['Operations Round', 'ops_head', 'video_call']],
        ],
        'csa_hyd' => [
            'designation' => 'DSG-CSA', 'department' => 'CS', 'location' => 'HYD', 'openings' => 15, 'type' => 'permanent',
            'salary' => [210000, 290000], 'experience' => [0, 2], 'qualification' => 'Graduate (any stream)',
            'skills' => ['English Communication', 'Customer Handling', 'Telugu', 'Hindi', 'CRM'], 'shift' => 'Rotational shifts',
            'priority' => 'urgent', 'opened' => 32, 'plan' => 'open', 'recruiters' => ['r_aman', 'r_divya'],
            'hiring_manager' => 'cs_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 6,
            'rounds' => [['HR Round', 'am_south', 'phone'], ['Operations Round', 'cs_head', 'video_call']],
        ],
        'da_pun' => [
            'designation' => 'DSG-DA', 'department' => 'OPS', 'location' => 'PUN', 'openings' => 20, 'type' => 'contract',
            'salary' => [180000, 250000], 'experience' => [0, 2], 'qualification' => '10th pass',
            'skills' => ['Two-wheeler', 'Driving Licence', 'Local Route Knowledge', 'Marathi'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 26, 'plan' => 'open', 'recruiters' => ['r_sneha', 'r_pooja'],
            'hiring_manager' => 'ops_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 5,
            'rounds' => [['Operations Round', 'ops_head', 'in_person']],
        ],
        'csm_blr' => [
            'designation' => 'DSG-CSM', 'department' => 'CS', 'location' => 'HO', 'openings' => 1, 'type' => 'permanent',
            'salary' => [900000, 1300000], 'experience' => [4, 8], 'qualification' => 'MBA',
            'skills' => ['Account Management', 'Renewals', 'Customer Onboarding', 'QBRs'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 4, 'plan' => 'approved', 'plan_days' => 1, 'recruiters' => ['r_divya'],
            'hiring_manager' => 'cs_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 0,
            'rounds' => [],
        ],
        'rsh_mum' => [
            'designation' => 'DSG-RSH', 'department' => 'SAL', 'location' => 'MUM', 'openings' => 1, 'type' => 'permanent',
            'salary' => [2500000, 3500000], 'experience' => [12, 18], 'qualification' => 'MBA',
            'skills' => ['Regional P&L', 'Sales Leadership', 'Channel Strategy', 'Team Building'], 'shift' => 'Day shift',
            'priority' => 'high', 'opened' => 3, 'plan' => 'pending_approval', 'plan_days' => 2, 'recruiters' => ['r_priya'],
            'hiring_manager' => 'sales_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 0,
            'rounds' => [],
        ],
        'pm_blr' => [
            'designation' => 'DSG-PM', 'department' => 'TECH', 'location' => 'HO', 'openings' => 1, 'type' => 'permanent',
            'salary' => [2000000, 3000000], 'experience' => [6, 10], 'qualification' => 'B.Tech + MBA preferred',
            'skills' => ['Product Discovery', 'Roadmapping', 'Analytics', 'Stakeholder Management'], 'shift' => 'Day shift',
            'priority' => 'medium', 'opened' => 1, 'plan' => 'draft', 'recruiters' => ['r_divya'],
            'hiring_manager' => 'tech_head', 'assistant_manager' => 'am_south', 'manager' => 'mgr_south', 'apps' => 0,
            'rounds' => [],
        ],
        'gd_pun' => [
            'designation' => 'DSG-GD', 'department' => 'MKT', 'location' => 'PUN', 'openings' => 1, 'type' => 'internship',
            'salary' => [120000, 180000], 'experience' => [0, 1], 'qualification' => 'Diploma / Degree in Design',
            'skills' => ['Figma', 'Adobe Illustrator', 'Canva', 'Social Media Creatives'], 'shift' => 'Day shift',
            'priority' => 'low', 'opened' => 62, 'plan' => 'cancelled', 'plan_days' => 55, 'recruiters' => ['r_sneha'],
            'hiring_manager' => 'mkt_head', 'assistant_manager' => 'am_west', 'manager' => 'mgr_west', 'apps' => 0,
            'rounds' => [],
        ],
    ];

    /**
     * @var array<int, string>
     */
    public const array FIRST_NAMES = [
        'Aarav', 'Aditi', 'Akash', 'Alisha', 'Anil', 'Anjali', 'Ankit', 'Aparna', 'Ashwin', 'Ayesha', 'Bhavna', 'Chetan',
        'Deepak', 'Divya', 'Farah', 'Gaurav', 'Harsha', 'Ishaan', 'Jaya', 'Karan', 'Kiran', 'Komal', 'Kunal', 'Madhuri',
        'Manish', 'Meghna', 'Mohit', 'Nandini', 'Naveen', 'Nidhi', 'Nikita', 'Omkar', 'Pallavi', 'Pranav', 'Preeti',
        'Rahul', 'Ramya', 'Ravi', 'Riya', 'Sachin', 'Sahil', 'Sameer', 'Sanjana', 'Saurabh', 'Shreya', 'Siddharth',
        'Simran', 'Sonal', 'Swati', 'Tarun', 'Tejas', 'Uday', 'Varsha', 'Vinay', 'Yash', 'Zoya', 'Abhishek', 'Bhavesh',
        'Chitra', 'Dinesh', 'Esha', 'Faizan', 'Geeta', 'Hemant', 'Isha', 'Jatin', 'Kavita', 'Lokesh', 'Mansi', 'Neeraj',
        'Poonam', 'Rajat', 'Sakshi', 'Tanya', 'Umesh', 'Vandana', 'Wasim', 'Yamini', 'Arpita', 'Gopal', 'Keerthi',
        'Mahesh', 'Nisha', 'Rohan', 'Shweta', 'Vishal', 'Asif', 'Bindu', 'Deepika', 'Girish', 'Harini', 'Jyoti',
    ];

    /**
     * @var array<int, string>
     */
    public const array LAST_NAMES = [
        'Agarwal', 'Ahmed', 'Bhat', 'Banerjee', 'Chauhan', 'Chopra', 'Das', 'Dutta', 'Fernandes', 'Ghosh', 'Goyal', 'Hegde',
        'Iyer', 'Jain', 'Joshi', 'Kamath', 'Khan', 'Kumar', 'Mishra', 'Mukherjee', 'Naidu', 'Pandey', 'Patil', 'Prasad',
        'Rajan', 'Rao', 'Rathore', 'Saxena', 'Sen', 'Sethi', 'Shetty', 'Sinha', 'Srivastava', 'Thakur', 'Tripathi',
        'Upadhyay', 'Varghese', 'Yadav', 'Bose', 'Chaudhary', 'D\'Souza', 'Gill', 'Kaur', 'Menon', 'Nambiar', 'Pawar',
        'Qureshi', 'Reddy', 'Shah', 'Soni', 'Trivedi', 'Venkatesh', 'Walia', 'Arora', 'Bhatt', 'Kulkarni', 'Pillai',
    ];

    /**
     * Candidates' current employers — fictitious.
     *
     * @var array<int, string>
     */
    public const array COMPANIES = [
        'Bluewave Retail', 'Sunrise Telecom', 'Crestline BPO', 'Northstar Logistics', 'Greenleaf Foods', 'Orbit Fintech',
        'Pinnacle Insurance', 'Silverline Motors', 'Everfresh Mart', 'Horizon Tech Labs', 'Kaveri Textiles', 'Metro Courier',
        'Lotus Healthcare', 'Quickserve Solutions', 'Zenith Software', 'Brightpath Education', 'Coastal Beverages',
        'Nimbus Cloudworks', 'Urban Homes Realty', 'Vertex Consulting', 'Trident Pharma', 'Apex Electricals',
        'Saffron Hospitality', 'Indus Paints', 'Rapid Freight', 'Cedar Analytics',
    ];

    /**
     * @var array<string, int>
     */
    public const array SOURCE_WEIGHTS = [
        'Naukri' => 24, 'Apna' => 12, 'LinkedIn' => 10, 'Indeed' => 9, 'Employee Referral' => 10, 'WorkIndia' => 7,
        'Walk-in' => 6, 'Website' => 6, 'WhatsApp' => 4, 'Internal Database' => 4, 'Agency' => 3, 'Facebook' => 3,
        'Instagram' => 2,
    ];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    public const array KNOWLEDGE_ARTICLES = [
        'Recruitment Policy' => ['policy', <<<'TEXT'
Purpose: every open position is hired through an approved requisition so headcount, budget and ownership are clear before sourcing starts.

1. Requisitions are raised by the hiring or recruitment manager, approved by the VP HR (or CHRO for leadership roles) and only then opened for sourcing.
2. Each open requisition has one accountable recruitment manager, one assistant manager and at least one recruiter.
3. Candidates must be contacted within 24 hours of sourcing and screened within 2 working days (see the SLA settings).
4. Every stage change is recorded in the system — no offline shortlists. Rejections and dropouts always carry a reason.
5. A requisition is closed once all openings have joined; if hiring pauses it is put On Hold with a written reason.
TEXT],
        'Interview Evaluation Guidelines' => ['interviews', <<<'TEXT'
Interviewers rate every candidate on four criteria — Technical, Communication, Problem Solving and Culture Fit — on a 1 to 5 scale, and add written feedback before the interview can be completed.

- 5: exceptional, clearly above the bar for the role
- 4: strong, meets every requirement
- 3: acceptable, some gaps that training can close
- 2: below the bar for the role
- 1: not suitable

Feedback must be submitted within 24 hours. Sales roles run an HR round followed by a Sales round; technology roles add a technical round and a final round with the engineering manager.
TEXT],
        'Offer Approval Matrix' => ['offers', <<<'TEXT'
Offers are drafted and initiated by the recruiter and released only by a Recruitment Manager, VP HR or CHRO.

- Up to the top of the requisition's salary band: Recruitment Manager may release.
- Up to 10% above band: VP HR approval required.
- Above that, or any joining bonus above Rs 50,000: CHRO approval required.

Offers are valid for 7 days. Offers still awaiting a decision after the expiry date are expired automatically every night.
TEXT],
        'Employee Referral Policy' => ['policy', <<<'TEXT'
Employees earn a referral bonus of Rs 7,500 when a referred candidate joins and completes 30 days. Referrals are logged with the source "Employee Referral" and the referring employee on the candidate record, so payouts are tracked in Recruitment Costs. Leadership roles (Head and above) are excluded.
TEXT],
        'Joining & Onboarding Checklist' => ['onboarding', <<<'TEXT'
Before day one: joining confirmed by the candidate, ID proof, address proof, education certificates, experience/relieving letters, last 3 salary slips and bank details collected.

Day one: documents verified by HR, system access requested, buddy assigned.

Within the first week: documents marked complete in the Joining Tracker, onboarding marked complete, and the joiner converted to an employee record.
TEXT],
        'Candidate Communication Templates' => ['communication', <<<'TEXT'
Interview invitation (WhatsApp): "Hi {name}, thank you for your interest in the {role} role. Your interview is scheduled on {date} at {time}. Please reply YES to confirm."

Offer follow-up (email): "Dear {name}, please find your offer letter attached. Kindly confirm your acceptance by {expiry}."

Joining reminder (call/WhatsApp): "Hi {name}, we are excited to have you join us on {doj}. Please carry the documents listed in the joining checklist."
TEXT],
    ];

    /**
     * @var array<int, string>
     */
    public const array POSITIVE_FEEDBACK = [
        'Clear communicator with good energy. Handled objection scenarios confidently.',
        'Strong fundamentals and relevant experience; answered situational questions well.',
        'Good attitude and learning mindset. Comfortable with targets and field work.',
        'Structured thinking, gave concrete examples from current role. Recommend moving ahead.',
        'Confident, well-prepared and understands the customer. Salary expectation within band.',
    ];

    /**
     * @var array<int, string>
     */
    public const array NEGATIVE_FEEDBACK = [
        'Communication needs improvement; struggled with basic role-play.',
        'Experience is not relevant to the role; limited exposure to the required tools.',
        'Could not explain past work in detail. Not a fit at this time.',
        'Expectations on salary and location do not match the role.',
    ];

    public static function personEmail(string $key): string
    {
        $person = self::PEOPLE[$key];

        return $person['login'] ?? strtolower($person['first'].'.'.$person['last']).'@example.com';
    }
}
