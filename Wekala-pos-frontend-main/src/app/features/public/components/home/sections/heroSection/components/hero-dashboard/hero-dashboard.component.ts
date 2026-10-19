import { ChangeDetectionStrategy, Component } from '@angular/core';
import { BrowserBarComponent } from './components/browser-bar/browser-bar.component';
import { GrowthCardComponent } from './components/growth-card.component/growth-card.component';
import { OrdersTableComponent } from './components/orders-table/orders-table.component';
import { SalesChartComponent } from './components/sales-chart/sales-chart.component';
import { StatsCardsComponent } from './components/stats-cards/stats-cards.component';

@Component({
  selector: 'app-hero-dashboard',
  imports: [BrowserBarComponent, GrowthCardComponent, OrdersTableComponent, SalesChartComponent, StatsCardsComponent],
  standalone:true,
  templateUrl: './hero-dashboard.component.html',
  styleUrl: './hero-dashboard.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HeroDashboardComponent {}
