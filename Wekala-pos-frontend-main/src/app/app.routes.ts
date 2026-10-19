import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./features/public/components/home/homeComponent/home.component').then(
        (m) => m.HomeComponent
      ),
  },

  {
    path: '**',
    redirectTo: '',
  },
];