<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained('submissions')->cascadeOnDelete();
            $table->string('nim', 50);
            $table->string('student_name', 150);
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->unsignedTinyInteger('meeting');
            $table->decimal('score', 5, 2);
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['nim', 'class_id', 'course_id', 'meeting'], 'grades_student_context_meeting_unique');
            $table->index(['class_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
