<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_read_references_and_submit_an_uppercase_nim_pdf(): void
    {
        Storage::fake('local');
        [$classroom, $course] = $this->references();

        $this->getJson('/api/v1/classes')->assertOk()->assertJsonPath('data.0.code', 'TI-1A');
        $this->getJson('/api/v1/courses')->assertOk()->assertJsonPath('data.0.code', 'IF101');

        $response = $this->post('/api/v1/submissions', [
            'nim' => 'tia001',
            'student_name' => 'Alya Putri',
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => 4,
            'file' => UploadedFile::fake()->create('tugas.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.nim', 'TIA001');
        $submission = Submission::query()->firstOrFail();
        Storage::disk('local')->assertExists($submission->stored_path);
    }

    public function test_submission_rejects_non_pdf_files_and_invalid_meeting(): void
    {
        Storage::fake('local');
        [$classroom, $course] = $this->references();

        $this->post('/api/v1/submissions', [
            'nim' => 'ABC001',
            'student_name' => 'Bima',
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => 15,
            'file' => UploadedFile::fake()->create('tugas.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meeting', 'file']);
    }

    public function test_admin_can_login_filter_submissions_and_open_a_file(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create([
            'email' => 'admin@kampus.ac.id',
            'password' => Hash::make('admin123'),
            'role' => 'super_admin',
        ]);
        [$classroom, $course] = $this->references();
        $file = UploadedFile::fake()->create('laporan.pdf', 50, 'application/pdf');
        $path = $file->store('submissions', 'local');
        $submission = Submission::query()->create([
            'nim' => 'IF001',
            'student_name' => 'Citra Lestari',
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => 7,
            'original_filename' => 'laporan.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 51200,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'admin123',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/submissions?search=IF001&meeting=7')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $submission->id);
        $this->get('/api/v1/submissions/'.$submission->id.'/file')->assertOk();
    }

    public function test_authenticated_admin_can_manage_classes_and_linked_data_cannot_be_deleted(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'member']);
        Sanctum::actingAs($admin);

        $classResponse = $this->postJson('/api/v1/classes', [
            'name' => 'ti-2a',
        ])->assertCreated()->assertJsonPath('data.code', 'TI-2A')->assertJsonPath('data.name', 'TI-2A');
        $classroom = Classroom::query()->findOrFail($classResponse->json('data.id'));
        $course = Course::query()->create(['code' => 'IF301', 'name' => 'Pemrograman API']);
        Submission::query()->create([
            'nim' => 'TI001',
            'student_name' => 'Daffa',
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => 1,
            'original_filename' => 'tugas.pdf',
            'stored_path' => 'submissions/tugas.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);

        $this->deleteJson('/api/v1/classes/'.$classroom->id)->assertStatus(409);
    }

    public function test_only_super_admin_can_manage_admin_accounts(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/admins')->assertForbidden();

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);
        $this->postJson('/api/v1/admins', [
            'email' => 'ANGGOTA@kampus.ac.id',
            'password' => 'password123',
        ])->assertCreated()->assertJsonPath('data.role', 'member');
        $this->assertDatabaseHas('users', ['email' => 'anggota@kampus.ac.id', 'role' => 'member']);
    }

    public function test_admin_can_create_and_update_a_grade_from_the_latest_resubmission(): void
    {
        $admin = User::factory()->create(['role' => 'member']);
        Sanctum::actingAs($admin);
        [$classroom, $course] = $this->references();
        $firstSubmission = $this->submission($classroom, $course, ['stored_path' => 'submissions/first.pdf']);
        $latestSubmission = $this->submission($classroom, $course, ['stored_path' => 'submissions/latest.pdf']);
        $ungradedSubmission = $this->submission($classroom, $course, [
            'nim' => 'TI002',
            'student_name' => 'Bima Pratama',
            'stored_path' => 'submissions/ungraded.pdf',
        ]);

        $this->getJson('/api/v1/grades/groups')
            ->assertOk()
            ->assertJsonPath('data.0.key', $classroom->id.'-'.$course->id)
            ->assertJsonPath('data.0.submission_count', 3)
            ->assertJsonPath('data.0.graded_count', 0);

        $this->putJson('/api/v1/submissions/'.$firstSubmission->id.'/grade', ['score' => 82.5])
            ->assertCreated()
            ->assertJsonPath('data.score', 82.5);

        $this->putJson('/api/v1/submissions/'.$latestSubmission->id.'/grade', ['score' => 91.25])
            ->assertOk()
            ->assertJsonPath('data.score', 91.25);

        $this->assertDatabaseCount('grades', 1);
        $this->assertDatabaseHas('grades', [
            'submission_id' => $latestSubmission->id,
            'nim' => 'TI001',
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => 1,
            'score' => 91.25,
            'graded_by' => $admin->id,
        ]);

        $this->getJson('/api/v1/grades')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['nim' => 'TI001', 'student_name' => 'Alya Putri'])
            ->assertJsonFragment(['nim' => 'TI002', 'student_name' => 'Bima Pratama'])
            ->assertJsonFragment(['code' => 'TI-1A', 'name' => 'Teknik Informatika — 1A'])
            ->assertJsonFragment(['code' => 'IF101', 'name' => 'Pemrograman Web'])
            ->assertJsonFragment(['1' => 91.25]);
    }

    public function test_grade_validation_and_authentication_are_enforced(): void
    {
        [$classroom, $course] = $this->references();
        $submission = $this->submission($classroom, $course);

        $this->putJson('/api/v1/submissions/'.$submission->id.'/grade', ['score' => 80])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'member']));
        $this->putJson('/api/v1/submissions/'.$submission->id.'/grade', ['score' => 101])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('score');
        $this->putJson('/api/v1/submissions/'.$submission->id.'/grade', ['score' => 90.123])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('score');
    }

    public function test_admin_can_export_one_group_as_excel_and_all_groups_as_zip(): void
    {
        $admin = User::factory()->create(['role' => 'member']);
        Sanctum::actingAs($admin);
        [$classroom, $course] = $this->references();
        $submission = $this->submission($classroom, $course);
        Grade::query()->create([
            'submission_id' => $submission->id,
            'nim' => $submission->nim,
            'student_name' => $submission->student_name,
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => $submission->meeting,
            'score' => 88,
            'graded_by' => $admin->id,
        ]);

        $excelResponse = $this->get('/api/v1/grades/export?class_id='.$classroom->id.'&course_id='.$course->id);
        $excelResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('Nilai_IF101_Pemrograman_Web_TI_1A.xlsx');
        $spreadsheet = IOFactory::load($excelResponse->baseResponse->getFile()->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertStringStartsWith('Nilai Mata Kuliah: IF101', (string) $sheet->getCell('A1')->getValue());
        $this->assertStringStartsWith('Kelas: TI-1A', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('NIM', $sheet->getCell('A4')->getValue());
        $this->assertSame('Mata Kuliah', $sheet->getCell('D4')->getValue());
        $this->assertSame('FFFF00', $sheet->getStyle('A4')->getFill()->getStartColor()->getRGB());
        $this->assertSame('A5', $sheet->getFreezePane());
        $spreadsheet->disconnectWorksheets();

        $this->get('/api/v1/grades/export-all')
            ->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertDownload('Rekap_Nilai.zip');
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/submissions')->assertUnauthorized();
        $this->getJson('/api/v1/grades')->assertUnauthorized();
        $this->getJson('/api/v1/grades/groups')->assertUnauthorized();
        $this->postJson('/api/v1/classes', ['code' => 'A', 'name' => 'A'])->assertUnauthorized();
    }

    private function references(): array
    {
        return [
            Classroom::query()->create(['code' => 'TI-1A', 'name' => 'Teknik Informatika — 1A']),
            Course::query()->create(['code' => 'IF101', 'name' => 'Pemrograman Web']),
        ];
    }

    private function submission(Classroom $classroom, Course $course, array $overrides = []): Submission
    {
        return Submission::query()->create(array_merge([
            'nim' => 'TI001',
            'student_name' => 'Alya Putri',
            'class_id' => $classroom->id,
            'course_id' => $course->id,
            'meeting' => 1,
            'original_filename' => 'laporan.pdf',
            'stored_path' => 'submissions/laporan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ], $overrides));
    }
}
