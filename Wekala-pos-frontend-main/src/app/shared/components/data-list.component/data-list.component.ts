import {
  ChangeDetectionStrategy,
  Component,
  input
} from '@angular/core';

export type DataListStatus =
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'default';

export interface DataListItem {

  id: string | number;

  title: string;

  subtitle?: string;

  value?: string | number;

  icon?: string;

  badge?: string;

  status?: DataListStatus;

}

@Component({
  selector: 'app-data-list',
  standalone: true,
  templateUrl: './data-list.component.html',
  styleUrl: './data-list.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class DataListComponent {

  readonly title = input.required<string>();

  readonly items = input.required<DataListItem[]>();

}