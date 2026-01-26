<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TodoResource\Pages;
use App\Models\Todo;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class TodoResource extends Resource
{
    protected static ?string $model = Todo::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                    
                Forms\Components\RichEditor::make('description')
                    ->maxLength(65535)
                    ->columnSpanFull()
                    ->disableToolbarButtons([
                        'codeBlock',
                    ]),
                
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pending',
                                'in_progress' => 'In Progress',
                                'completed' => 'Completed',
                                'delayed' => 'Delayed',
                            ])
                            ->default('pending')
                            ->required()
                            ->native(false),
                            
                        Forms\Components\Select::make('priority')
                            ->options([
                                'low' => 'Low',
                                'medium' => 'Medium',
                                'high' => 'High',
                                'urgent' => 'Urgent',
                            ])
                            ->default('medium')
                            ->required()
                            ->native(false),
                    ]),
                    
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\DatePicker::make('due_date')
                            ->label('Due Date')
                            ->native(false)
                            ->displayFormat('m/d/Y'),
                            
                        Forms\Components\Toggle::make('is_completed')
                            ->label('Mark as Completed')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {
                                if ($state) {
                                    $set('status', 'completed');
                                } elseif ($state === false) {
                                    $set('status', 'pending');
                                }
                            }),
                    ]),
                    
                Forms\Components\Select::make('assigned_to')
                    ->label('Assigned To')
                    ->multiple()
                    ->options(User::all()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Select one or multiple users to assign this task to.')
                    ->columnSpanFull(),
                    
                Forms\Components\TextInput::make('created_by')
                    ->label('Created By')
                    ->default(auth()->user()?->name)
                    ->readOnly()
                    ->dehydrated(),
                    
                Forms\Components\FileUpload::make('attachments')
                    ->label('File Attachments')
                    ->multiple()
                    ->disk('public')
                    ->directory('todo-attachments')
                    ->downloadable()
                    ->openable()
                    ->previewable()
                    ->maxSize(102400) // 100 MB
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/*',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain',
                        'application/zip',
                    ])
                    ->columnSpanFull()
                    ->helperText('Upload files up to 100MB. Supported: PDF, images, Word, Excel, text, ZIP.'),
                    
                Forms\Components\Hidden::make('created_by_user_id')
                    ->default(auth()->id()),
                    
                Forms\Components\Hidden::make('assigned_by')
                    ->default(auth()->user()?->name),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Todo Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->size('lg')
                            ->weight('bold')
                            ->columnSpanFull(),
                            
                        Infolists\Components\TextEntry::make('description')
                            ->formatStateUsing(fn (string $state = null): HtmlString => new HtmlString(
                                $state ? str($state)->sanitizeHtml()->toString() : '<em class="text-gray-500">No description provided</em>'
                            ))
                            ->columnSpanFull(),
                            
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state = null): string => match ($state) {
                                'pending' => 'Pending',
                                'in_progress' => 'In Progress',
                                'completed' => 'Completed',
                                'delayed' => 'Delayed',
                                default => 'Pending',
                            })
                            ->color(fn (string $state = null): string => match ($state) {
                                'pending' => 'warning',
                                'in_progress' => 'info',
                                'completed' => 'success',
                                'delayed' => 'danger',
                                default => 'gray',
                            }),
                            
                        Infolists\Components\TextEntry::make('priority')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'low' => 'secondary',
                                'medium' => 'primary',
                                'high' => 'warning',
                                'urgent' => 'danger',
                                default => 'secondary',
                            }),
                            
                        Infolists\Components\TextEntry::make('due_date')
                            ->label('Due Date')
                            ->date('F j, Y')
                            ->visible(fn ($record) => !empty($record->due_date))
                            ->placeholder(''),
                            
                        Infolists\Components\TextEntry::make('assigned_to')
                            ->label('Assigned To')
                            ->formatStateUsing(function ($state, $record) {
                                if (empty($state)) {
                                    return 'Unassigned';
                                }
                                if (is_array($state)) {
                                    // Convert string IDs to integers
                                    $ids = array_map('intval', $state);
                                    $users = User::whereIn('id', $ids)->pluck('name')->toArray();
                                    return !empty($users) ? implode(', ', $users) : 'Unassigned';
                                }
                                return 'Unassigned';
                            })
                            ->badge()
                            ->separator(','),
                            
                        Infolists\Components\TextEntry::make('assigned_by')
                            ->label('Assigned By')
                            ->formatStateUsing(fn ($state, $record) => $state ?? $record->created_by ?? 'Unknown'),
                            
                        Infolists\Components\TextEntry::make('created_by')
                            ->label('Created By')
                            ->formatStateUsing(function ($state, $record) {
                                // If created_by is a number (user ID), look up the user
                                if (is_numeric($state)) {
                                    $user = User::find((int)$state);
                                    return $user ? $user->name : 'User #' . $state;
                                }
                                return $state ?? 'Unknown';
                            }),
                            
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M j, Y g:i A'),
                            
                        Infolists\Components\TextEntry::make('completed_at')
                            ->label('Completed')
                            ->dateTime('M j, Y g:i A')
                            ->visible(fn ($record) => !empty($record->completed_at))
                            ->placeholder(''),
                    ])
                    ->columns(2),
                    
                Section::make('File Attachments')
                    ->schema([
                        Infolists\Components\TextEntry::make('attachments')
                            ->label('')
                            ->formatStateUsing(function ($state) {
                                if (empty($state) || !is_array($state)) {
                                    return new HtmlString('<em class="text-gray-500">No attachments</em>');
                                }
                                
                                $links = [];
                                foreach ($state as $file) {
                                    $filename = basename($file);
                                    $url = \Storage::disk('public')->url($file);
                                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                    
                                    // Choose icon based on file type
                                    $icon = match($extension) {
                                        'pdf' => '<svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 18h12V6h-4V2H4v16zm-2 1V0h12l4 4v16H2v-1z"/></svg>',
                                        'jpg', 'jpeg', 'png', 'gif', 'webp' => '<svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z"/></svg>',
                                        'doc', 'docx' => '<svg class="w-5 h-5 text-blue-700" fill="currentColor" viewBox="0 0 20 20"><path d="M4 18h12V6h-4V2H4v16zm-2 1V0h12l4 4v16H2v-1z"/></svg>',
                                        'xls', 'xlsx' => '<svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 18h12V6h-4V2H4v16zm-2 1V0h12l4 4v16H2v-1z"/></svg>',
                                        'zip' => '<svg class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 18h12V6h-4V2H4v16zm-2 1V0h12l4 4v16H2v-1z"/></svg>',
                                        default => '<svg class="w-5 h-5 text-gray-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 18h12V6h-4V2H4v16zm-2 1V0h12l4 4v16H2v-1z"/></svg>',
                                    };
                                    
                                    $links[] = sprintf(
                                        '<div class="flex items-center gap-2 py-2 px-3 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                            %s
                                            <a href="%s" target="_blank" download class="text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 hover:underline flex-1 font-medium text-sm">%s</a>
                                            <a href="%s" download class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" title="Download">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </a>
                                        </div>',
                                        $icon,
                                        $url,
                                        $filename,
                                        $url
                                    );
                                }
                                
                                return new HtmlString('<div class="space-y-2 w-full">' . implode('', $links) . '</div>');
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false)
                    ->visible(fn ($record) => !empty($record->attachments)),
                    
                Section::make('Update History')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('updates')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('username')
                                    ->weight('bold')
                                    ->color('primary'),
                                    
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Posted')
                                    ->dateTime('M j, Y g:i A')
                                    ->color('gray'),
                                    
                                Infolists\Components\TextEntry::make('comment')
                                    ->columnSpanFull()
                                    ->prose(),
                            ])
                            ->columns(2)
                            ->contained(false),
                    ])
                    ->collapsible()
                    ->collapsed(false)
                    ->visible(fn ($record) => $record->updates()->exists())
                    ->description('Progress notes and updates posted by team members'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->wrap()
                    ->grow(),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'delayed' => 'Delayed',
                        default => 'Pending',
                    })
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'delayed',
                    ])
                    ->sortable(),
                    
                Tables\Columns\BadgeColumn::make('priority')
                    ->colors([
                        'secondary' => 'low',
                        'primary' => 'medium',
                        'warning' => 'high',
                        'danger' => 'urgent',
                    ])
                    ->sortable()
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->date('m/d/Y')
                    ->sortable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),
                    
                Tables\Columns\TextColumn::make('assigned_to')
                    ->label('Assigned')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '-';
                        }
                        if (is_array($state)) {
                            // Convert string IDs to integers
                            $ids = array_map('intval', $state);
                            $users = User::whereIn('id', $ids)->pluck('name')->toArray();
                            return !empty($users) ? implode(', ', $users) : '-';
                        }
                        return '-';
                    })
                    ->searchable()
                    ->toggleable()
                    ->limit(30)
                    ->wrap(),
                    
                Tables\Columns\TextColumn::make('created_by')
                    ->label('Creator')
                    ->sortable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),
                    
                Tables\Columns\IconColumn::make('attachments')
                    ->label('Files')
                    ->icon(fn ($state) => $state && is_array($state) && count($state) > 0 ? 'heroicon-o-paper-clip' : null)
                    ->color('primary')
                    ->toggleable()
                    ->toggledHiddenByDefault(),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'delayed' => 'Delayed',
                    ]),
                    
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->tooltip('View Details'),
                Tables\Actions\Action::make('toggle')
                    ->label('')
                    ->icon(fn (Todo $record) => $record->status === 'completed' ? 'heroicon-o-arrow-path' : 'heroicon-o-check-circle')
                    ->color(fn (Todo $record) => $record->status === 'completed' ? 'warning' : 'success')
                    ->tooltip(fn (Todo $record) => $record->status === 'completed' ? 'Reopen Task' : 'Mark as Complete')
                    ->action(function (Todo $record) {
                        if ($record->status === 'completed') {
                            $record->update([
                                'status' => 'pending',
                                'is_completed' => false,
                                'completed_at' => null,
                            ]);
                        } else {
                            $record->update([
                                'status' => 'completed',
                                'is_completed' => true,
                                'completed_at' => now(),
                            ]);
                        }
                    }),
                Tables\Actions\EditAction::make()
                    ->label('')
                    ->tooltip('Edit'),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->reorderable('sort')
            ->recordUrl(
                fn (Todo $record): string => Pages\ViewTodo::getUrl([$record->id]),
            );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTodos::route('/'),
            'create' => Pages\CreateTodo::route('/create'),
            'view' => Pages\ViewTodo::route('/{record}'),
            'edit' => Pages\EditTodo::route('/{record}/edit'),
        ];
    }
}
