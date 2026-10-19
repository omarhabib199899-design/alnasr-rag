import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { FeatureCardComponent } from '../../../../../../../../shared/components/cards/feature-card/feature-card.component';

/**
 * Interface defining the structure of a system feature.
 */
interface FeatureItem {
  title: string;
  description: string;
  icon: string;
}

@Component({
  selector: 'app-features-grid',
  standalone: true,
  imports: [
    FeatureCardComponent
  ],
  templateUrl: './features-grid.component.html',
  styleUrl: './features-grid.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class FeaturesGridComponent {

  // Signal containing the comprehensive list of system features
  readonly features = signal<FeatureItem[]>([
    {
      title: 'Point of Sale',
      description: 'Process sales fast through a modern, cashier-friendly interface.',
      icon: 'cart-shopping'
    },
    {
      title: 'Products & Categories',
      description: 'Manage products, services, categories, prices, variants and add-ons.',
      icon: 'cube'
    },
    {
      title: 'Inventory',
      description: 'Track stock quantities, monitor availability and manage movements.',
      icon: 'cubes'
    },
    {
      title: 'Order Management',
      description: 'Dine-in, takeaway, pickup, delivery, retail and online orders.',
      icon: 'clipboard-list'
    },
    {
      title: 'Customers',
      description: 'Save customer info, review history and improve service quality.',
      icon: 'user-group'
    },
    {
      title: 'Multi-Branch',
      description: 'Manage multiple branches, products, users and sales centrally.',
      icon: 'building'
    },
    {
      title: 'Payments & Invoices',
      description: 'Support multiple payment methods and generate professional invoices.',
      icon: 'file-invoice-dollar'
    },
    {
      title: 'Reports & Analytics',
      description: 'Monitor sales, revenue, products, orders and branch performance.',
      icon: 'chart-column'
    },
    {
      title: 'Roles & Permissions',
      description: 'Control employee access by role and responsibility.',
      icon: 'shield-check'
    },
    {
      title: 'ZATCA Integration',
      description: 'Electronic invoicing integration for businesses in Saudi Arabia.',
      icon: 'file-invoice'
    }
  ]);

}