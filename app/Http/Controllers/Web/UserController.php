<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AssignRolesRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly UserRepository $userRepository
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('users.index', [
            'users' => $this->users->paginate(withTrashed: true),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        $data = [
            'companies' => $this->userRepository->companiesForSelect(),
            'roles' => $this->userRepository->rolesForSelect(),
        ];

        if ($request->ajax()) {
            return view('users.partials.create-form', $data);
        }

        return view('users.create', $data);
    }

    public function store(StoreUserRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->users->create($request->validated());

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario creado correctamente.',
                'data' => ['id' => $user->id],
            ], 201);
        }

        return redirect()->route('users.index')->with('success', 'Usuario creado correctamente.');
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('view', $user);

        $user->load(['company', 'roles']);

        if ($request->ajax()) {
            return view('users.partials.show', compact('user'));
        }

        return view('users.show', compact('user'));
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorize('update', $user);

        $data = [
            'user' => $user->load('roles'),
            'companies' => $this->userRepository->companiesForSelect(),
            'roles' => $this->userRepository->rolesForSelect($user),
        ];

        if ($request->ajax()) {
            return view('users.partials.edit-form', $data);
        }

        return view('users.edit', $data);
    }

    public function changePasswordForm(Request $request, User $user): View
    {
        $this->authorize('changePassword', $user);

        if ($request->ajax()) {
            return view('users.partials.change-password-modal', compact('user'));
        }

        return view('users.partials.change-password-modal', compact('user'));
    }

    public function rolesForm(Request $request, User $user): View
    {
        $this->authorize('assignRoles', $user);

        $data = [
            'user' => $user->load('roles'),
            'roles' => $this->userRepository->rolesForSelect($user),
        ];

        if ($request->ajax()) {
            return view('users.partials.roles-form', $data);
        }

        return view('users.partials.roles-form', $data);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $user);

        $user = $this->users->update($user, $request->validated());

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente.',
                'data' => ['id' => $user->id],
            ]);
        }

        return redirect()->route('users.index')->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        try {
            $this->users->delete($user, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('users.index')->with('success', 'Usuario eliminado correctamente.');
    }

    public function toggleStatus(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $user);
        $isCurrentUser = $request->user()?->is($user) ?? false;

        try {
            $user = $this->users->toggleStatus($user);
        } catch (ValidationException $exception) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => collect($exception->errors())->flatten()->first(),
                    'data' => $exception->errors(),
                ], 422);
            }

            return back()->withErrors($exception->errors());
        }

        $message = $user->is_active ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.';

        if ($isCurrentUser && ! $user->is_active) {
            return $this->logoutCurrentUser($request, $message);
        }

        if ($request->ajax()) {
            return response()->json(['success' => true, 'message' => $message, 'data' => ['id' => $user->id]]);
        }

        return redirect()->route('users.index')->with('success', $message);
    }

    public function changePassword(ChangePasswordRequest $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('changePassword', $user);
        $isCurrentUser = $request->user()?->is($user) ?? false;

        $this->users->changePassword($user, $request->validated('password'));

        if ($isCurrentUser) {
            return $this->logoutCurrentUser($request, 'Contraseña actualizada correctamente.');
        }

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente.',
                'data' => ['id' => $user->id],
            ]);
        }

        return redirect()->route('users.index')->with('success', 'Contraseña actualizada correctamente.');
    }

    private function logoutCurrentUser(Request $request, string $message): JsonResponse|RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => null,
                'redirect_url' => route('login'),
            ]);
        }

        return redirect()->route('login')->with('success', $message);
    }

    public function assignRoles(AssignRolesRequest $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('assignRoles', $user);

        try {
            $user = $this->users->syncRoles($user, $request->validated('roles') ?? []);
        } catch (ValidationException $exception) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => collect($exception->errors())->flatten()->first(),
                    'data' => $exception->errors(),
                ], 422);
            }

            return back()->withErrors($exception->errors());
        }

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Roles actualizados correctamente.',
                'data' => ['id' => $user->id],
            ]);
        }

        return redirect()->route('users.index')->with('success', 'Roles actualizados correctamente.');
    }

    public function restore(Request $request, int|string $user): JsonResponse|RedirectResponse
    {
        $user = $this->users->findWithTrashed($user);

        $this->authorize('restore', $user);

        $user = $this->users->restore($user);

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Usuario restaurado correctamente.',
                'data' => ['id' => $user->id],
            ]);
        }

        return redirect()->route('users.index')->with('success', 'Usuario restaurado correctamente.');
    }
}
