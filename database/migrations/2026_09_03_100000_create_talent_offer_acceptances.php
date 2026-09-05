<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A candidate's answer to an offer, and the employee it became.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_100000_create_talent_offer_acceptances.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_100000_create_talent_offer_acceptances.php
 *
 * ── WHY A NEW TABLE RATHER THAN A COLUMN ON talent_offers ───────────────────
 *
 * talent_offers.status is a real MySQL ENUM('draft','sent','rejected','expired')
 * with no 'accepted' member, so an offer could be rejected but never accepted -
 * TalentOfferController had store(), index() and reject() and no accept().
 * Widening that ENUM means an ALTER TABLE rebuild on live, and the house rule
 * is VARCHAR + a PHP const precisely so that never has to happen again.
 *
 * Acceptance also needs more than a status: a token the candidate can act on
 * without logging in, an expiry, a single-use marker, and a link to the tbluser
 * row the acceptance produced. That is a record in its own right.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * Live is MariaDB 10.1.48, InnoDB, ROW_FORMAT=Compact - dev is Dynamic, so dev
 * accepts index prefixes live rejects. The cap here is 767 bytes.
 *
 *   - NO json column. Live carries zero and has no native JSON type.
 *   - Nothing indexes a VARCHAR(191). The widest key below is
 *     toff_accept_token_unique at char(64) = 256 bytes under utf8mb4.
 *   - Every index and foreign key is NAMED. Laravel would generate
 *     `talent_offer_acceptances_acceptance_token_hash_unique` at 53 characters,
 *     which fits - but the same generator has already produced a 66-character
 *     name in this codebase and failed outright. Naming them is the habit.
 *   - `decision` is VARCHAR + TalentOfferController::DECISIONS, never ENUM.
 *
 * ── TENANCY ─────────────────────────────────────────────────────────────────
 *
 * Root aggregate. It could inherit through offer_id, but the token is redeemed
 * by an unauthenticated candidate whose only identifier IS the token - there is
 * no session to derive a tenant from, so the row has to carry its own and the
 * redemption path checks the offer agrees.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('talent_offer_acceptances')) {
            return;
        }

        Schema::create('talent_offer_acceptances', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');
            $table->string('syear', 50);

            $table->unsignedBigInteger('offer_id');
            // Denormalised from the offer so the kanban and the hire path can join
            // without a second hop. Kept in step by the controller, never by a trigger.
            $table->unsignedBigInteger('application_id')->nullable();

            /*
             * The HASH of the token, never the token. A leaked database row must not
             * be redeemable. char(64) is a sha256 in hex - fixed width, so the unique
             * index is 256 bytes under utf8mb4, comfortably inside the 767-byte cap
             * that live's ROW_FORMAT=Compact imposes.
             */
            $table->char('acceptance_token_hash', 64)->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->dateTime('token_used_at')->nullable();

            // TalentOfferController::DECISIONS - pending | accepted | declined
            $table->string('decision', 20)->default('pending');
            $table->dateTime('decided_at')->nullable();
            // Who answered: 'candidate' when redeemed through a link, 'hr' when an
            // internal user recorded the answer on the candidate's behalf.
            $table->string('decided_via', 20)->nullable();

            // The tbluser row this acceptance produced. Deliberately NOT a foreign
            // key: deactivating or removing an employee must not fail because an
            // historical acceptance points at them.
            $table->unsignedBigInteger('accepted_employee_id')->nullable();

            // Kept for idempotency and audit: the address the offer was answered
            // for, even if the application row is later edited.
            $table->string('candidate_email', 255)->nullable();

            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'syear'], 'toff_accept_tenant_idx');
            $table->index(['offer_id'], 'toff_accept_offer_idx');
            $table->index(['application_id'], 'toff_accept_application_idx');
            $table->index(['accepted_employee_id'], 'toff_accept_employee_idx');
            $table->unique(['acceptance_token_hash'], 'toff_accept_token_unique');

            $table->foreign('offer_id', 'toff_accept_offer_fk')
                ->references('id')->on('talent_offers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if ($this->tableExists('talent_offer_acceptances')) {
            Schema::drop('talent_offer_acceptances');
        }
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue. */
    private function tableExists(string $table): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->c ?? 0) > 0;
    }
};
