<?php

namespace App\Services;

use App\Models\Grade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GradeReportService
{
    public const MEETING_COUNT = 14;

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
        return $grades
            ->groupBy(fn (Grade $grade) => implode('|', [$grade->nim, $grade->class_id, $grade->course_id]))
            ->map(function (Collection $studentGrades): array {
                /** @var Grade $latest */
                $latest = $studentGrades->sortByDesc('updated_at')->first();
                $meetings = array_fill(1, self::MEETING_COUNT, null);

                foreach ($studentGrades as $grade) {
                    $meetings[$grade->meeting] = (float) $grade->score;
                }

                return [
                    'nim' => $latest->nim,
                    'student_name' => $latest->student_name,
                    'class' => [
                        'id' => $latest->classroom->id,
                        'code' => $latest->classroom->code,
                        'name' => $latest->classroom->name,
                    ],
                    'course' => [
                        'id' => $latest->course->id,
                        'code' => $latest->course->code,
                        'name' => $latest->course->name,
                    ],
                    'meetings' => $meetings,
                ];
            })
            ->sortBy(fn (array $row) => implode('|', [$row['class']['code'], $row['course']['code'], $row['nim']]))
            ->values()
            ->all();
    }
}
