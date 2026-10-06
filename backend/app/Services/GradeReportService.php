<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GradeReportService
{
    public const MEETING_COUNT = 14;

    public function groups(): array
    {
        $pairs = Submission::query()
            ->select(['class_id', 'course_id'])
            ->with(['classroom', 'course'])
            ->distinct()
            ->get();
        $submissionCounts = Submission::query()
            ->selectRaw('class_id, course_id, COUNT(*) as total')
            ->groupBy(['class_id', 'course_id'])
            ->get()
            ->keyBy(fn (Submission $submission) => $this->groupKey($submission->class_id, $submission->course_id));
        $gradeCounts = Grade::query()
            ->selectRaw('class_id, course_id, COUNT(*) as total')
            ->groupBy(['class_id', 'course_id'])
            ->get()
            ->keyBy(fn (Grade $grade) => $this->groupKey($grade->class_id, $grade->course_id));

        return $pairs
            ->map(function (Submission $submission) use ($submissionCounts, $gradeCounts): array {
                $key = $this->groupKey($submission->class_id, $submission->course_id);

                return [
                    'key' => $key,
                    'class' => $this->referenceData($submission->classroom),
                    'course' => $this->referenceData($submission->course),
                    'submission_count' => (int) ($submissionCounts->get($key)->total ?? 0),
                    'graded_count' => (int) ($gradeCounts->get($key)->total ?? 0),
                ];
            })
            ->sortBy(fn (array $group) => implode('|', [$group['class']['code'], $group['course']['code']]))
            ->values()
            ->all();
    }

    public function rowsForFilters(array $filters = []): array
    {
        return $this->rowsFromCollections(
            $this->submissionQuery($filters)->get(),
            $this->query($filters)->get(),
        );
    }

    public function query(array $filters = []): Builder
    {
        $query = Grade::query()->with(['classroom', 'course']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn (Builder $builder) => $builder
                ->where('nim', 'like', "%{$search}%")
                ->orWhere('student_name', 'like', "%{$search}%"));
        }
        if (! empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }
        if (! empty($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        return $query
            ->orderBy('class_id')
            ->orderBy('course_id')
            ->orderBy('nim')
            ->orderBy('meeting');
    }

    public function rows(Collection $grades): array
    {
        return $this->rowsFromCollections(collect(), $grades);
    }

    private function submissionQuery(array $filters = []): Builder
    {
        $query = Submission::query()->with(['classroom', 'course']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn (Builder $builder) => $builder
                ->where('nim', 'like', "%{$search}%")
                ->orWhere('student_name', 'like', "%{$search}%"));
        }
        if (! empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }
        if (! empty($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        return $query->latest();
    }

    private function rowsFromCollections(Collection $submissions, Collection $grades): array
    {
        $rows = [];
        $latestSubmissionAt = [];

        foreach ($submissions as $submission) {
            $key = $this->groupStudentKey($submission->nim, $submission->class_id, $submission->course_id);
            $submittedAt = $submission->created_at?->getTimestamp() ?? 0;
            if (isset($rows[$key]) && ($latestSubmissionAt[$key] ?? 0) >= $submittedAt) {
                continue;
            }
            $latestSubmissionAt[$key] = $submittedAt;
            $rows[$key] = $this->emptyRow(
                $submission->nim,
                $submission->student_name,
                $submission->classroom,
                $submission->course,
            );
        }

        foreach ($grades as $grade) {
            $key = $this->groupStudentKey($grade->nim, $grade->class_id, $grade->course_id);
            if (! isset($rows[$key])) {
                $rows[$key] = $this->emptyRow($grade->nim, $grade->student_name, $grade->classroom, $grade->course);
            }
            $rows[$key]['meetings'][$grade->meeting] = (float) $grade->score;
        }

        return collect($rows)
            ->sortBy(fn (array $row) => implode('|', [$row['class']['code'], $row['course']['code'], $row['nim']]))
            ->values()
            ->all();
    }

    private function emptyRow(string $nim, string $studentName, object $classroom, object $course): array
    {
        return [
            'nim' => $nim,
            'student_name' => $studentName,
            'class' => $this->referenceData($classroom),
            'course' => $this->referenceData($course),
            'meetings' => array_fill(1, self::MEETING_COUNT, null),
        ];
    }

    private function referenceData(object $reference): array
    {
        return [
            'id' => $reference->id,
            'code' => $reference->code,
            'name' => $reference->name,
        ];
    }

    private function groupKey(int $classId, int $courseId): string
    {
        return "{$classId}-{$courseId}";
    }

    private function groupStudentKey(string $nim, int $classId, int $courseId): string
    {
        return implode('|', [$nim, $classId, $courseId]);
    }
}
