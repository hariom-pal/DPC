<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public header — `layouts/portal`, which dresses the home page, the login and
 * registration screens and every Self-Inspection screen.
 *
 * Two faults reported on 2026-09-06, both of them things that only show on a page
 * or in a state nobody had asserted:
 *
 *  - It offered **Department Login** and **New Registration** to an officer who was
 *    already signed in — which is how a department comes to register itself twice.
 *  - Its menu linked to bare `#about`-style fragments, and those sections exist on
 *    the home page alone, so on four screens out of five the whole menu was inert
 *    while looking live.
 */
final class PublicHeaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The public header offers the right buttons for the visitor it has
     * (reported 2026-09-06: *"if a department is already logged in it should not
     * show Login and Registration"*).
     *
     * Both directions, because the fault was that one state was never checked at
     * all: offering "New Registration" to an officer with a session is how a
     * department comes to register itself twice.
     */
    public function test_the_home_page_offers_login_only_to_a_visitor(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('ui.department_login'))
            ->assertSee('href="'.route('login').'"', false)
            ->assertDontSee(__('ui.logout'));
    }

    /**
     * No public menu offers registration (2026-10-26): a department registers only
     * through the invitation link the Portal Super Admin sends it.
     */
    public function test_no_public_page_links_to_registration(): void
    {
        foreach (['/', route('login'), route('self-inspection.landing')] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertDontSee('href="'.route('register').'"', false)
                ->assertDontSee(__('ui.new_registration'));
        }
    }

    public function test_the_home_page_offers_the_dashboard_and_logout_to_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee(__('ui.dashboard_link'))
            ->assertSee(__('ui.logout'))
            /*
             | Asserted on the **links**, not on the words.
             |
             | "Department Login" is also the title of a step in the process-flow
             | section — descriptive content that belongs on the page whoever is
             | reading it. What must not survive a sign-in is a *route* to register
             | or to log in again, and that is what this checks.
             */
            ->assertDontSee('href="'.route('register').'"', false)
            ->assertDontSee('href="'.route('login').'"', false);
    }

    /**
     * The top menu works from every page in this layout, not only the home page.
     *
     * The links were bare `#about` fragments, and the sections they name exist on
     * the home page alone — so on the login screen, the registration screen and
     * every Self-Inspection screen the whole menu did nothing. Asserted on `/login`
     * for that reason: on `/` a bare fragment would have passed.
     */
    public function test_the_top_menu_links_work_away_from_the_home_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('home').'#about', false)
            ->assertSee(route('home').'#process', false)
            ->assertSee(route('home').'#faq', false)
            ->assertDontSee('href="#about"', false);
    }
}
