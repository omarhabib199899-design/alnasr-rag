import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

/**
 * Interface defining the structure of a recent order.
 */
interface Order {
  id: string;
  customer: string;
  type: string;
  amount: number;
}

/**
 * Component displaying a table of recent transactions within the dashboard.
 */
@Component({
  selector: 'app-orders-table',
  standalone: true,
  imports: [],
  templateUrl: './orders-table.component.html',
  styleUrl: './orders-table.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class OrdersTableComponent {

  // Section title
  readonly title = signal('Recent Orders');

  // Signal containing the list of recent orders
  readonly orders = signal<Order[]>([
    {
      id: '#4082',
      customer: 'Table 06',
      type: 'Dine-in',
      amount: 420
    },
    {
      id: '#4081',
      customer: 'Online',
      type: '3 items',
      amount: 180
    },
    {
      id: '#4080',
      customer: 'Retail',
      type: 'Walk-in',
      amount: 96
    }
  ]);

}