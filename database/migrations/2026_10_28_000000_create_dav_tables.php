<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sabre/dav 4.7.1 stores calendars and address books itself, but it only
 * ships SQL examples. These tables match examples/sql for SQLite and
 * PostgreSQL. sabre's principals, groupmembers and users tables are not
 * created: Laravel already owns identity.
 *
 * Blob columns are bytea on PostgreSQL. PDO may return those as streams.
 * Foreign keys tie objects to their collection. Rows that point at a
 * principal by URI are removed in DavDataCleaner when the user is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('calendars', function (Blueprint $table): void {
            $table->id();
            $table->integer('synctoken')->default(1);
            // sabre's PostgreSQL script uses varchar(21), which cannot hold
            // VEVENT,VTODO,VJOURNAL (22 characters).
            $table->string('components', 255);
        });

        Schema::create('calendarinstances', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('calendarid')->constrained('calendars')->cascadeOnDelete();
            $table->string('principaluri', 255)->nullable();
            $table->unsignedSmallInteger('access')->default(1);
            $table->string('displayname', 255)->nullable();
            $table->string('uri', 200);
            $table->text('description')->nullable();
            $table->integer('calendarorder')->default(0);
            $table->string('calendarcolor', 10)->nullable();
            $table->text('timezone')->nullable();
            $table->unsignedSmallInteger('transparent')->default(0);
            $table->string('share_href', 100)->nullable();
            $table->string('share_displayname', 100)->nullable();
            $table->unsignedSmallInteger('share_invitestatus')->default(2);

            $table->unique(['principaluri', 'uri']);

            if ($driver === 'pgsql') {
                $table->unique(['principaluri', 'calendarid']);
                $table->unique(['principaluri', 'share_href']);
            } else {
                $table->unique(['calendarid', 'principaluri']);
                $table->unique(['calendarid', 'share_href']);
            }
        });

        Schema::create('calendarobjects', function (Blueprint $table): void {
            $table->id();
            $table->binary('calendardata');
            $table->string('uri', 200);
            $table->foreignId('calendarid')->constrained('calendars')->cascadeOnDelete();
            $table->integer('lastmodified');
            $table->string('etag', 32);
            $table->integer('size');
            $table->string('componenttype', 8)->nullable();
            $table->integer('firstoccurence')->nullable();
            $table->integer('lastoccurence')->nullable();
            $table->string('uid', 200)->nullable();
            $table->unique(['calendarid', 'uri']);
            $table->index(['calendarid', 'firstoccurence'], 'calendarid_time');
        });

        Schema::create('calendarchanges', function (Blueprint $table): void {
            $table->id();
            $table->string('uri', 200);
            $table->integer('synctoken');
            $table->foreignId('calendarid')->constrained('calendars')->cascadeOnDelete();
            $table->integer('operation');
            $table->index(['calendarid', 'synctoken'], 'calendarid_synctoken');
        });

        Schema::create('calendarsubscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('uri', 200);
            $table->string('principaluri', 255);
            $table->text('source');
            $table->string('displayname', 255)->nullable();
            $table->string('refreshrate', 32)->nullable();
            $table->integer('calendarorder')->default(0);
            $table->string('calendarcolor', 10)->nullable();
            $table->unsignedSmallInteger('striptodos')->nullable();
            $table->unsignedSmallInteger('stripalarms')->nullable();
            $table->unsignedSmallInteger('stripattachments')->nullable();
            $table->integer('lastmodified')->nullable();
            $table->unique(['principaluri', 'uri']);
        });

        Schema::create('schedulingobjects', function (Blueprint $table): void {
            $table->id();
            $table->string('principaluri', 255);
            $table->binary('calendardata')->nullable();
            $table->string('uri', 200);
            $table->integer('lastmodified')->nullable();
            $table->string('etag', 32);
            $table->integer('size');
            $table->unique(['principaluri', 'uri']);
        });

        Schema::create('addressbooks', function (Blueprint $table): void {
            $table->id();
            $table->string('principaluri', 255);
            $table->string('displayname', 255)->nullable();
            $table->string('uri', 200);
            $table->text('description')->nullable();
            $table->integer('synctoken')->default(1);
            $table->unique(['principaluri', 'uri']);
        });

        Schema::create('cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addressbookid')->constrained('addressbooks')->cascadeOnDelete();
            $table->binary('carddata')->nullable();
            $table->string('uri', 200);
            $table->integer('lastmodified')->nullable();
            $table->string('etag', 32)->nullable();
            $table->integer('size');
            $table->unique(['addressbookid', 'uri']);
        });

        Schema::create('addressbookchanges', function (Blueprint $table): void {
            $table->id();
            $table->string('uri', 200);
            $table->integer('synctoken');
            $table->foreignId('addressbookid')->constrained('addressbooks')->cascadeOnDelete();
            $table->integer('operation');
            $table->index(['addressbookid', 'synctoken'], 'addressbookid_synctoken');
        });

        Schema::create('propertystorage', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 1024);
            $table->string('name', 255);
            $table->integer('valuetype')->nullable();
            $table->binary('value')->nullable();
            $table->unique(['path', 'name']);
        });

        Schema::create('locks', function (Blueprint $table): void {
            $table->id();
            $table->string('owner', 100)->nullable();
            $table->integer('timeout')->nullable();
            $table->integer('created')->nullable();
            $table->string('token', 100)->nullable();
            $table->unsignedSmallInteger('scope')->nullable();
            $table->unsignedSmallInteger('depth')->nullable();
            $table->text('uri')->nullable();
            $table->index('token');
            $table->index('uri');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locks');
        Schema::dropIfExists('propertystorage');
        Schema::dropIfExists('addressbookchanges');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('addressbooks');
        Schema::dropIfExists('schedulingobjects');
        Schema::dropIfExists('calendarsubscriptions');
        Schema::dropIfExists('calendarchanges');
        Schema::dropIfExists('calendarobjects');
        Schema::dropIfExists('calendarinstances');
        Schema::dropIfExists('calendars');
    }
};
