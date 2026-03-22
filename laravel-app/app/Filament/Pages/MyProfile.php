<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class MyProfile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user';

    protected static string $view = 'filament.pages.my-profile';

    protected static ?string $navigationLabel = 'My Profile';

    protected static ?int $navigationSort = 100;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            '

display_name' => Auth::user()->display_name,
            'rank' => Auth::user()->rank,
            'station' => Auth::user()->station,
            'phone' => Auth::user()->phone,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Profile Information')
                    ->schema([
                        Forms\Components\TextInput::make('display_name')
                            ->label('Display Name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rank')
                            ->label('Rank')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('station')
                            ->label('Station')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Changes')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Auth::user()->update($data);

        Notification::make()
            ->success()
            ->title('Profile updated successfully')
            ->send();
    }
}